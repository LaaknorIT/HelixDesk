<?php

namespace App\Services\Mail\Fetchers;

use App\Models\MailAccount;
use App\Services\Mail\Contracts\InboundMailFetcher;
use App\Services\Mail\MailInboundLogger;
use App\Services\MicrosoftEntra\MicrosoftGraphHttpClient;
use App\Support\MailAccountEntraResolver;

class MicrosoftGraphInboundMailFetcher implements InboundMailFetcher
{
    public function __construct(
        private readonly MailAccountEntraResolver $entraResolver,
        private readonly MailInboundLogger $logger,
    ) {}

    /**
     * @return list<array{raw: string, uid: string|null}>
     */
    public function fetchNewMessages(MailAccount $account): array
    {
        $creds = $this->entraResolver->resolve($account);
        $client = new MicrosoftGraphHttpClient(
            directoryTenantId: $creds['azure_tenant_id'],
            clientId: $creds['application_client_id'],
            clientSecret: $creds['client_secret'],
        );

        $mailboxUpn = rawurlencode($creds['mailbox_upn']);
        $path = "users/{$mailboxUpn}/mailFolders/inbox/messages";

        $messages = [];
        $skippedEmptyId = 0;
        foreach ($client->paginateCollection($path, [
            '$filter' => 'isRead eq false',
            '$top' => 25,
            '$select' => 'id',
        ]) as $row) {
            $messageId = (string) ($row['id'] ?? '');
            if ($messageId === '') {
                $skippedEmptyId++;

                continue;
            }

            $raw = $client->getRaw("users/{$mailboxUpn}/messages/{$messageId}/\$value");
            $messages[] = [
                'raw' => $raw,
                'uid' => $messageId,
            ];
        }

        $this->logger->info($account, 'Microsoft Graph inbox fetch completed', [
            'mailbox_upn' => $creds['mailbox_upn'],
            'unread_fetched' => count($messages),
            'skipped_empty_id' => $skippedEmptyId,
        ]);

        return $messages;
    }

    public function acknowledgeMessage(MailAccount $account, ?string $transportUid): void
    {
        if ($transportUid === null) {
            return;
        }

        $creds = $this->entraResolver->resolve($account);
        $client = new MicrosoftGraphHttpClient(
            directoryTenantId: $creds['azure_tenant_id'],
            clientId: $creds['application_client_id'],
            clientSecret: $creds['client_secret'],
        );

        $mailboxUpn = rawurlencode($creds['mailbox_upn']);
        $client->patchJson("users/{$mailboxUpn}/messages/{$transportUid}", [
            'isRead' => true,
        ]);
    }
}
