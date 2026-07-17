<?php

namespace App\Services\Mail;

use App\Enums\InboundMailIngestOutcome;
use App\Enums\WorkItemSource;
use App\Enums\WorkItemType;
use App\Models\MailAccount;
use App\Models\ProcessedInboundEmail;
use App\Models\Queue;
use App\Models\WorkItem;
use App\Models\WorkItemMessage;
use App\Models\WorkItemMessageAttachment;
use App\Services\SlaPolicyResolver;
use App\Services\SlaTimerService;
use App\Services\WorkItemAutomationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class InboundMailIngestionService
{
    public function __construct(
        private readonly InboundMimeParser $mimeParser,
        private readonly RequesterResolutionService $requesterResolver,
        private readonly MailInboundLogger $logger,
        private readonly WorkItemAutomationService $automationService,
        private readonly SlaPolicyResolver $slaPolicyResolver,
        private readonly SlaTimerService $slaTimerService,
    ) {}

    public function ingest(MailAccount $account, string $rawMime, ?string $transportUid = null): InboundMailIngestOutcome
    {
        $parsed = $this->mimeParser->parse($rawMime, $transportUid);

        if ($parsed->fromAddress === '') {
            $this->logger->warning($account, 'Skipping inbound message with empty From', [
                'transport_uid' => $transportUid,
                'message_id' => $parsed->messageId,
                'subject' => $parsed->subject,
            ]);

            return InboundMailIngestOutcome::SkippedEmptyFrom;
        }

        if (ProcessedInboundEmail::query()
            ->where('mail_account_id', $account->getKey())
            ->where('message_id', $parsed->messageId)
            ->exists()) {
            $this->logger->info($account, 'Skipping duplicate inbound message', [
                'transport_uid' => $transportUid,
                'message_id' => $parsed->messageId,
            ]);

            return InboundMailIngestOutcome::Duplicate;
        }

        $threadMatch = $this->resolveWorkItemForInbound($account, $parsed);
        $existing = $threadMatch['work_item'];

        if ($existing === null && $this->resolveQueueForAccount($account) === null) {
            $this->logger->warning($account, 'Skipping inbound message: no queue linked to mail account', [
                'transport_uid' => $transportUid,
                'message_id' => $parsed->messageId,
                'from' => $parsed->fromAddress,
                'subject' => $parsed->subject,
                ...$this->threadingDiagnosticContext($parsed, $account, $threadMatch),
            ]);

            return InboundMailIngestOutcome::SkippedNoQueue;
        }

        $ingestedWorkItemId = null;

        DB::transaction(function () use ($account, $parsed, $existing, &$ingestedWorkItemId): void {
            ProcessedInboundEmail::query()->create([
                'tenant_id' => $account->tenant_id,
                'mail_account_id' => $account->getKey(),
                'message_id' => $parsed->messageId,
                'processed_at' => Carbon::now(),
            ]);

            $workItem = $existing ?? $this->createWorkItem($account, $parsed);
            $ingestedWorkItemId = $workItem->getKey();

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

            if ($existing !== null) {
                $target = $this->automationService->handleCustomerReplied($workItem, $message);
                $ingestedWorkItemId = $target->getKey();
            } else {
                $this->slaPolicyResolver->attachBestPolicy($workItem);
                $this->slaTimerService->startTimers($workItem);
            }
        });

        $this->logger->info($account, 'Ingested inbound message', [
            'transport_uid' => $transportUid,
            'message_id' => $parsed->messageId,
            'from' => $parsed->fromAddress,
            'subject' => $parsed->subject,
            'threaded' => $existing !== null,
            'work_item_id' => $ingestedWorkItemId,
            ...$this->threadingDiagnosticContext($parsed, $account, $threadMatch),
        ]);

        return InboundMailIngestOutcome::Ingested;
    }

    private function createWorkItem(MailAccount $account, ParsedInboundEmail $parsed): WorkItem
    {
        $queue = $this->resolveQueueForAccount($account);
        if ($queue === null) {
            throw new \RuntimeException('No queue available for mail account '.$account->getKey());
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

    /**
     * @return array{work_item: ?WorkItem, match_method: string, reference_candidates: list<string>, plus_token: ?string, subject_token: ?string}
     */
    private function resolveWorkItemForInbound(MailAccount $account, ParsedInboundEmail $parsed): array
    {
        $referenceCandidates = $this->collectThreadMessageIds($parsed);
        $plusToken = WorkItemMailReference::extractPlusTokenFromAddresses(
            $parsed->toAddresses,
            $account->email_address,
        );
        $subjectToken = WorkItemMailReference::extractSubjectToken($parsed->subject);

        $byMessageId = $this->findWorkItemByMessageIds($account, $referenceCandidates);
        if ($byMessageId !== null) {
            return [
                'work_item' => $byMessageId,
                'match_method' => 'message_id',
                'reference_candidates' => $referenceCandidates,
                'plus_token' => $plusToken,
                'subject_token' => $subjectToken,
            ];
        }

        if ($plusToken !== null) {
            $byPlus = $this->findWorkItemByPublicId($account, $plusToken);
            if ($byPlus !== null) {
                return [
                    'work_item' => $byPlus,
                    'match_method' => 'plus_address',
                    'reference_candidates' => $referenceCandidates,
                    'plus_token' => $plusToken,
                    'subject_token' => $subjectToken,
                ];
            }
        }

        if ($subjectToken !== null) {
            $bySubject = $this->findWorkItemByPublicId($account, $subjectToken);
            if ($bySubject !== null) {
                return [
                    'work_item' => $bySubject,
                    'match_method' => 'subject_token',
                    'reference_candidates' => $referenceCandidates,
                    'plus_token' => $plusToken,
                    'subject_token' => $subjectToken,
                ];
            }
        }

        return [
            'work_item' => null,
            'match_method' => 'none',
            'reference_candidates' => $referenceCandidates,
            'plus_token' => $plusToken,
            'subject_token' => $subjectToken,
        ];
    }

    /**
     * @param  list<string>  $candidateIds
     */
    private function findWorkItemByMessageIds(MailAccount $account, array $candidateIds): ?WorkItem
    {
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

    private function findWorkItemByPublicId(MailAccount $account, string $publicId): ?WorkItem
    {
        return WorkItem::query()
            ->where('tenant_id', $account->tenant_id)
            ->whereRaw('LOWER(public_id) = ?', [Str::lower($publicId)])
            ->first();
    }

    /**
     * @param  array{work_item: ?WorkItem, match_method: string, reference_candidates: list<string>, plus_token: ?string, subject_token: ?string}  $threadMatch
     * @return array<string, mixed>
     */
    private function threadingDiagnosticContext(ParsedInboundEmail $parsed, MailAccount $account, array $threadMatch): array
    {
        return [
            'in_reply_to' => $parsed->inReplyTo,
            'reference_candidates' => $threadMatch['reference_candidates'],
            'plus_token' => $threadMatch['plus_token'],
            'subject_token' => $threadMatch['subject_token'],
            'match_method' => $threadMatch['match_method'],
            'mail_account_supports_plus_addressing' => (bool) $account->supports_plus_addressing,
            'matched_work_item_id' => $threadMatch['work_item']?->getKey(),
            'matched_public_id' => $threadMatch['work_item']?->public_id,
        ];
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
