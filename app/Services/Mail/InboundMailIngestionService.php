<?php

namespace App\Services\Mail;

use App\Enums\WorkItemSource;
use App\Enums\WorkItemType;
use App\Models\MailAccount;
use App\Models\ProcessedInboundEmail;
use App\Models\Queue;
use App\Models\WorkItem;
use App\Models\WorkItemMessage;
use App\Models\WorkItemMessageAttachment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class InboundMailIngestionService
{
    public function __construct(
        private readonly InboundMimeParser $mimeParser,
        private readonly RequesterResolutionService $requesterResolver,
    ) {}

    public function ingest(MailAccount $account, string $rawMime, ?string $transportUid = null): void
    {
        $parsed = $this->mimeParser->parse($rawMime, $transportUid);

        if ($parsed->fromAddress === '') {
            return;
        }

        if (ProcessedInboundEmail::query()
            ->where('mail_account_id', $account->getKey())
            ->where('message_id', $parsed->messageId)
            ->exists()) {
            return;
        }

        DB::transaction(function () use ($account, $parsed): void {
            ProcessedInboundEmail::query()->create([
                'tenant_id' => $account->tenant_id,
                'mail_account_id' => $account->getKey(),
                'message_id' => $parsed->messageId,
                'processed_at' => Carbon::now(),
            ]);

            $workItem = $this->resolveWorkItem($account, $parsed);

            if ($workItem === null) {
                return;
            }

            $requester = $this->requesterResolver->resolveOrCreate(
                tenantId: (int) $account->tenant_id,
                email: $parsed->fromAddress,
                displayName: $parsed->fromName,
                organizationId: $account->organization_id,
            );

            if ($workItem->reported_by_user_id === null) {
                $workItem->reported_by_user_id = $requester->getKey();
                $workItem->save();
            }

            $parentMessage = $this->resolveParentMessage($workItem, $parsed);

            /** @var WorkItemMessage $message */
            $message = WorkItemMessage::query()->create([
                'tenant_id' => $account->tenant_id,
                'work_item_id' => $workItem->getKey(),
                'author_user_id' => $requester->getKey(),
                'parent_message_id' => $parentMessage?->getKey(),
                'is_internal' => false,
                'body' => $parsed->bodyText !== '' ? $parsed->bodyText : $parsed->subject,
                'external_message_id' => $parsed->messageId,
                'in_reply_to' => $parsed->inReplyTo,
                'email_references' => $parsed->references,
                'from_address' => $parsed->fromAddress,
                'to_addresses' => $parsed->toAddresses,
                'mail_account_id' => $account->getKey(),
            ]);

            $this->storeAttachments($message, $parsed->attachments);
        });
    }

    private function resolveWorkItem(MailAccount $account, ParsedInboundEmail $parsed): ?WorkItem
    {
        $existing = $this->findWorkItemForReply($account, $parsed);
        if ($existing !== null) {
            return $existing;
        }

        $queue = $this->resolveQueueForAccount($account);
        if ($queue === null) {
            return null;
        }

        $requester = $this->requesterResolver->resolveOrCreate(
            tenantId: (int) $account->tenant_id,
            email: $parsed->fromAddress,
            displayName: $parsed->fromName,
            organizationId: $account->organization_id,
        );

        return WorkItem::query()->create([
            'tenant_id' => $account->tenant_id,
            'organization_id' => $account->organization_id,
            'reported_by_user_id' => $requester->getKey(),
            'assigned_agent_id' => null,
            'queue_id' => $queue->getKey(),
            'type' => WorkItemType::Incident,
            'title' => Str::limit($parsed->subject, 250),
            'description' => $parsed->bodyText,
            'priority' => 'normal',
            'category_id' => null,
            'status' => 'New',
            'source' => WorkItemSource::Email,
            'mail_account_id' => $account->getKey(),
            'inbound_message_id' => $parsed->messageId,
            'opened_at' => Carbon::now(),
            'resolved_at' => null,
            'closed_at' => null,
        ]);
    }

    private function findWorkItemForReply(MailAccount $account, ParsedInboundEmail $parsed): ?WorkItem
    {
        $candidateIds = $this->collectThreadMessageIds($parsed);
        if ($candidateIds === []) {
            return null;
        }

        $message = WorkItemMessage::query()
            ->where('tenant_id', $account->tenant_id)
            ->whereIn('external_message_id', $candidateIds)
            ->first();

        if ($message === null) {
            return WorkItem::query()
                ->where('tenant_id', $account->tenant_id)
                ->whereIn('inbound_message_id', $candidateIds)
                ->first();
        }

        return $message->workItem;
    }

    /**
     * @return list<string>
     */
    private function collectThreadMessageIds(ParsedInboundEmail $parsed): array
    {
        $ids = [];
        if ($parsed->inReplyTo !== null) {
            $ids[] = $parsed->inReplyTo;
        }

        if ($parsed->references !== null) {
            preg_match_all('/<[^>]+>/', $parsed->references, $matches);
            foreach ($matches[0] ?? [] as $match) {
                $ids[] = $match;
            }
        }

        return array_values(array_unique($ids));
    }

    private function resolveParentMessage(WorkItem $workItem, ParsedInboundEmail $parsed): ?WorkItemMessage
    {
        if ($parsed->inReplyTo === null) {
            return null;
        }

        return WorkItemMessage::query()
            ->where('work_item_id', $workItem->getKey())
            ->where('external_message_id', $parsed->inReplyTo)
            ->first();
    }

    private function resolveQueueForAccount(MailAccount $account): ?Queue
    {
        $queue = $account->queues()->first();
        if ($queue !== null) {
            return $queue;
        }

        return Queue::query()
            ->where('tenant_id', $account->tenant_id)
            ->orderBy('id')
            ->first();
    }

    /**
     * @param  list<array{filename: string, mime_type: string|null, content: string}>  $attachments
     */
    private function storeAttachments(WorkItemMessage $message, array $attachments): void
    {
        $disk = (string) config('helixdesk.mail.attachment_disk', 'local');
        $basePath = (string) config('helixdesk.mail.attachment_path', 'work-item-mail-attachments');

        foreach ($attachments as $attachment) {
            $filename = $attachment['filename'];
            $safeName = Str::uuid()->toString().'_'.basename($filename);
            $path = $basePath.'/'.$message->tenant_id.'/'.$message->getKey().'/'.$safeName;

            Storage::disk($disk)->put($path, $attachment['content']);

            WorkItemMessageAttachment::query()->create([
                'tenant_id' => $message->tenant_id,
                'work_item_message_id' => $message->getKey(),
                'filename' => $filename,
                'mime_type' => $attachment['mime_type'],
                'size' => strlen($attachment['content']),
                'storage_path' => $path,
            ]);
        }
    }
}
