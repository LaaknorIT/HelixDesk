<?php

namespace App\Services\Mail;

use App\Enums\MailOutboundProtocol;
use App\Models\MailAccount;
use App\Models\WorkItem;
use App\Models\WorkItemMessage;
use App\Services\MicrosoftEntra\MicrosoftGraphHttpClient;
use App\Support\MailAccountEntraResolver;
use Illuminate\Mail\Message as LaravelMessage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class OutboundWorkItemMailService
{
    public function __construct(private readonly MailAccountEntraResolver $entraResolver) {}

    public function sendAgentReply(WorkItemMessage $message): void
    {
        if ($message->is_internal || $message->email_sent_at !== null) {
            return;
        }

        $workItem = $message->workItem()->with(['queue.defaultMailAccount', 'reportedBy', 'mailAccount'])->first();
        if ($workItem === null) {
            return;
        }

        $author = $message->author;
        if ($author === null || $author->type === 'end_user') {
            return;
        }

        $recipient = $workItem->reportedBy;
        if ($recipient === null || $recipient->email === null || trim($recipient->email) === '') {
            return;
        }

        $mailAccount = $this->resolveOutboundAccount($workItem);
        if ($mailAccount === null) {
            Log::warning('No outbound mail account configured for work item reply.', [
                'work_item_id' => $workItem->getKey(),
                'message_id' => $message->getKey(),
            ]);

            return;
        }

        $messageId = '<'.Str::uuid()->toString().'@helixdesk>';
        $fromAddress = $mailAccount->email_address;
        $inReplyTo = $workItem->inbound_message_id;
        $references = $this->buildReferencesChain($workItem, $inReplyTo);
        $subject = 'Re: '.$workItem->title;

        $fromName = $author->name;

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
            Log::warning('Google Workspace Gmail API outbound mail is not implemented yet.', [
                'work_item_id' => $workItem->getKey(),
                'message_id' => $message->getKey(),
                'mail_account_id' => $mailAccount->getKey(),
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

        $message->update([
            'external_message_id' => $messageId,
            'in_reply_to' => $inReplyTo,
            'email_references' => $references,
            'from_address' => $fromAddress,
            'to_addresses' => [$recipient->email],
            'mail_account_id' => $mailAccount->getKey(),
            'email_sent_at' => now(),
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
        $internetHeaders = [];
        if ($inReplyTo !== null) {
            $internetHeaders[] = ['name' => 'In-Reply-To', 'value' => $inReplyTo];
        }
        if ($references !== null) {
            $internetHeaders[] = ['name' => 'References', 'value' => $references];
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

        if ($internetHeaders !== []) {
            $payload['message']['internetMessageHeaders'] = $internetHeaders;
        }

        try {
            $client->postJson("users/{$mailboxUpn}/sendMail", $payload);
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            if (str_contains($message, '403') || str_contains($message, 'ErrorAccessDenied')) {
                throw new \RuntimeException(
                    $message.' Sending mail requires Microsoft Graph application permission Mail.Send (with admin consent) on the Entra app used by this mail account.',
                    0,
                    $e,
                );
            }

            throw $e;
        }
    }
}
