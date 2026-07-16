<?php

namespace App\Services\Mail;

use App\Enums\MailOutboundProtocol;
use App\Models\MailAccount;
use App\Models\WorkItem;
use App\Models\WorkItemMessage;
use App\Services\MicrosoftEntra\MicrosoftGraphHttpClient;
use App\Support\MailAccountEntraResolver;
use Illuminate\Mail\Message as LaravelMessage;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

class OutboundWorkItemMailService
{
    public function __construct(
        private readonly MailAccountEntraResolver $entraResolver,
        private readonly MailInboundLogger $mailLogger,
    ) {}

    public function sendAgentReply(WorkItemMessage $message): void
    {
        if ($message->is_internal) {
            $this->mailLogger->logger()->debug('Skipping outbound reply: message is an internal note.', [
                'work_item_id' => $message->work_item_id,
                'work_item_message_id' => $message->getKey(),
            ]);

            return;
        }

        if ($message->email_sent_at !== null) {
            $this->mailLogger->logger()->debug('Skipping outbound reply: message already sent.', [
                'work_item_id' => $message->work_item_id,
                'work_item_message_id' => $message->getKey(),
                'email_sent_at' => $message->email_sent_at?->toIso8601String(),
            ]);

            return;
        }

        $workItem = $message->workItem()->with(['queue.defaultMailAccount', 'reportedBy', 'mailAccount'])->first();
        if ($workItem === null) {
            $this->mailLogger->logger()->warning('Skipping outbound reply: work item not found.', [
                'work_item_id' => $message->work_item_id,
                'work_item_message_id' => $message->getKey(),
            ]);

            return;
        }

        $author = $message->author;
        if ($author === null || $author->type === 'end_user') {
            $this->mailLogger->logger()->warning('Skipping outbound reply: author is missing or an end user.', [
                'work_item_id' => $workItem->getKey(),
                'work_item_message_id' => $message->getKey(),
                'author_user_id' => $message->author_user_id,
                'author_type' => $author?->type,
            ]);

            return;
        }

        $recipient = $workItem->reportedBy;
        if ($recipient === null || $recipient->email === null || trim($recipient->email) === '') {
            $this->mailLogger->logger()->warning('Skipping outbound reply: requester has no email address.', [
                'work_item_id' => $workItem->getKey(),
                'work_item_message_id' => $message->getKey(),
                'reported_by_user_id' => $workItem->reported_by_user_id,
            ]);

            return;
        }

        $mailAccount = $this->resolveOutboundAccount($workItem);
        if ($mailAccount === null) {
            $this->mailLogger->logger()->warning('No outbound mail account configured for work item reply.', [
                'work_item_id' => $workItem->getKey(),
                'work_item_message_id' => $message->getKey(),
                'work_item_mail_account_id' => $workItem->mail_account_id,
                'queue_id' => $workItem->queue_id,
                'queue_default_mail_account_id' => $workItem->queue?->default_mail_account_id,
            ]);

            return;
        }

        $messageId = '<'.Str::uuid()->toString().'@helixdesk>';
        $fromAddress = $mailAccount->email_address;
        $inReplyTo = $workItem->inbound_message_id;
        $references = $this->buildReferencesChain($workItem, $inReplyTo);
        $subject = 'Re: '.$workItem->title;
        $fromName = $author->name;
        $outboundProtocol = $mailAccount->outbound_protocol?->value;

        $this->mailLogger->info($mailAccount, 'Attempting outbound work item reply mail.', [
            'work_item_id' => $workItem->getKey(),
            'work_item_message_id' => $message->getKey(),
            'to' => $recipient->email,
            'from' => $fromAddress,
            'subject' => $subject,
            'outbound_protocol' => $outboundProtocol,
            'in_reply_to' => $inReplyTo,
            'has_references' => $references !== null,
        ]);

        try {
            if ($mailAccount->outbound_protocol === MailOutboundProtocol::MicrosoftGraph) {
                $this->sendViaGraph(
                    mailAccount: $mailAccount,
                    to: $recipient->email,
                    fromAddress: $fromAddress,
                    fromName: $fromName,
                    subject: $subject,
                    body: $message->body,
                    inReplyTo: $inReplyTo,
                    references: $references,
                );
            } elseif ($mailAccount->outbound_protocol === MailOutboundProtocol::GoogleGmailApi) {
                $this->mailLogger->warning($mailAccount, 'Google Workspace Gmail API outbound mail is not implemented yet.', [
                    'work_item_id' => $workItem->getKey(),
                    'work_item_message_id' => $message->getKey(),
                ]);

                return;
            } else {
                $this->sendViaSmtp(
                    mailAccount: $mailAccount,
                    to: $recipient->email,
                    fromAddress: $fromAddress,
                    fromName: $fromName,
                    subject: $subject,
                    body: $message->body,
                    inReplyTo: $inReplyTo,
                    references: $references,
                    messageId: $messageId,
                    message: $message,
                );
            }
        } catch (Throwable $e) {
            $this->mailLogger->exception($mailAccount, 'Outbound work item reply mail send failed.', $e, [
                'work_item_id' => $workItem->getKey(),
                'work_item_message_id' => $message->getKey(),
                'to' => $recipient->email,
                'outbound_protocol' => $outboundProtocol,
            ]);

            throw $e;
        }

        $message->update([
            'external_message_id' => $messageId,
            'in_reply_to' => $inReplyTo,
            'email_references' => $references,
            'from_address' => $fromAddress,
            'to_addresses' => [$recipient->email],
            'mail_account_id' => $mailAccount->getKey(),
            'email_sent_at' => now(),
        ]);

        $this->mailLogger->info($mailAccount, 'Outbound work item reply mail sent.', [
            'work_item_id' => $workItem->getKey(),
            'work_item_message_id' => $message->getKey(),
            'to' => $recipient->email,
            'external_message_id' => $messageId,
            'outbound_protocol' => $outboundProtocol,
        ]);
    }

    private function resolveOutboundAccount(WorkItem $workItem): ?MailAccount
    {
        if ($workItem->mail_account_id !== null) {
            return $workItem->mailAccount;
        }

        $queue = $workItem->queue;
        if ($queue?->default_mail_account_id !== null) {
            return $queue->defaultMailAccount;
        }

        return null;
    }

    private function buildReferencesChain(WorkItem $workItem, ?string $inReplyTo): ?string
    {
        $refs = [];
        if ($workItem->inbound_message_id !== null) {
            $refs[] = $workItem->inbound_message_id;
        }

        $latestInbound = $workItem->messages()
            ->whereNotNull('external_message_id')
            ->where('is_internal', false)
            ->orderByDesc('id')
            ->value('external_message_id');

        if (is_string($latestInbound) && $latestInbound !== '' && ! in_array($latestInbound, $refs, true)) {
            $refs[] = $latestInbound;
        }

        if ($inReplyTo !== null && ! in_array($inReplyTo, $refs, true)) {
            $refs[] = $inReplyTo;
        }

        if ($refs === []) {
            return null;
        }

        return implode(' ', $refs);
    }

    private function sendViaSmtp(
        MailAccount $mailAccount,
        string $to,
        string $fromAddress,
        string $fromName,
        string $subject,
        string $body,
        ?string $inReplyTo,
        ?string $references,
        string $messageId,
        WorkItemMessage $message,
    ): void {
        $settings = is_array($mailAccount->connection_settings) ? $mailAccount->connection_settings : [];
        $mailerName = 'mail_account_'.$mailAccount->getKey();

        config([
            'mail.mailers.'.$mailerName => [
                'transport' => 'smtp',
                'host' => $settings['smtp_host'] ?? $settings['host'] ?? 'localhost',
                'port' => (int) ($settings['smtp_port'] ?? 587),
                'encryption' => $settings['smtp_encryption'] ?? 'tls',
                'username' => $settings['smtp_username'] ?? $mailAccount->imapUsername(),
                'password' => $settings['smtp_password'] ?? $mailAccount->imapPassword(),
                'timeout' => null,
            ],
        ]);

        Mail::mailer($mailerName)->send([], [], function (LaravelMessage $laravelMessage) use (
            $to,
            $fromAddress,
            $fromName,
            $subject,
            $body,
            $inReplyTo,
            $references,
            $messageId,
            $message,
        ): void {
            $symfony = $laravelMessage->getSymfonyMessage();
            if ($symfony instanceof Email) {
                $symfony->from(new Address($fromAddress, $fromName));
                $symfony->to($to);
                $symfony->subject($subject);
                $symfony->text($body);
                $symfony->getHeaders()->addIdHeader('Message-ID', trim($messageId, '<>'));
                if ($inReplyTo !== null) {
                    $symfony->getHeaders()->addTextHeader('In-Reply-To', $inReplyTo);
                }
                if ($references !== null) {
                    $symfony->getHeaders()->addTextHeader('References', $references);
                }

                foreach ($message->attachments as $attachment) {
                    $disk = (string) config('helixdesk.mail.attachment_disk', 'local');
                    $contents = Storage::disk($disk)->get($attachment->storage_path);
                    $symfony->attach($contents, $attachment->filename, $attachment->mime_type);
                }
            }
        });
    }

    private function sendViaGraph(
        MailAccount $mailAccount,
        string $to,
        string $fromAddress,
        string $fromName,
        string $subject,
        string $body,
        ?string $inReplyTo,
        ?string $references,
    ): void {
        $creds = $this->entraResolver->resolve($mailAccount);
        $client = new MicrosoftGraphHttpClient(
            directoryTenantId: $creds['azure_tenant_id'],
            clientId: $creds['application_client_id'],
            clientSecret: $creds['client_secret'],
        );

        $mailboxUpn = rawurlencode($creds['mailbox_upn']);

        // Graph only allows custom headers via internetMessageHeaders (names must start with x-/X-).
        // Standard In-Reply-To / References must use MAPI extended properties instead.
        $extendedProperties = [];
        if ($inReplyTo !== null && trim($inReplyTo) !== '') {
            $extendedProperties[] = [
                'id' => 'String 0x1042', // PidTagInReplyToId
                'value' => $inReplyTo,
            ];
        }
        if ($references !== null && trim($references) !== '') {
            $extendedProperties[] = [
                'id' => 'String 0x1039', // PidTagInternetReferences
                'value' => $references,
            ];
        }

        $payload = [
            'message' => [
                'subject' => $subject,
                'body' => [
                    'contentType' => 'Text',
                    'content' => $body,
                ],
                'from' => [
                    'emailAddress' => [
                        'address' => $fromAddress,
                        'name' => $fromName,
                    ],
                ],
                'toRecipients' => [
                    [
                        'emailAddress' => [
                            'address' => $to,
                        ],
                    ],
                ],
            ],
            'saveToSentItems' => true,
        ];

        if ($extendedProperties !== []) {
            $payload['message']['singleValueExtendedProperties'] = $extendedProperties;
        }

        $this->mailLogger->debug($mailAccount, 'Sending outbound reply via Microsoft Graph sendMail.', [
            'mailbox_upn' => $creds['mailbox_upn'],
            'has_in_reply_to_extended_property' => $inReplyTo !== null && trim($inReplyTo) !== '',
            'has_references_extended_property' => $references !== null && trim($references) !== '',
        ]);

        try {
            $client->postJson("users/{$mailboxUpn}/sendMail", $payload);
        } catch (Throwable $e) {
            $errorMessage = $e->getMessage();
            if (str_contains($errorMessage, '403') || str_contains($errorMessage, 'ErrorAccessDenied')) {
                throw new \RuntimeException(
                    $errorMessage.' Sending mail requires Microsoft Graph application permission Mail.Send (with admin consent) on the Entra app used by this mail account.',
                    0,
                    $e,
                );
            }

            throw $e;
        }
    }
}
