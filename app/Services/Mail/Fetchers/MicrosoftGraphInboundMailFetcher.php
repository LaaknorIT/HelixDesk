<?php

namespace App\Services\Mail\Fetchers;

use App\Models\MailAccount;
use App\Services\Mail\Contracts\InboundMailFetcher;
use App\Services\Mail\MailInboundLogger;
use App\Services\MicrosoftEntra\MicrosoftGraphHttpClient;
use App\Support\MailAccountEntraResolver;
use RuntimeException;
use Throwable;

class MicrosoftGraphInboundMailFetcher implements InboundMailFetcher
{
    private const SYNC_STATE_PROCESSED_IDS = 'graph_processed_message_ids';

    private const MAX_TRACKED_PROCESSED_IDS = 500;

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
        $alreadyProcessed = $this->processedMessageIds($account);

        $messages = [];
        $skippedEmptyId = 0;
        $skippedAlreadyProcessed = 0;
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

            if (isset($alreadyProcessed[$messageId])) {
                $skippedAlreadyProcessed++;

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
            'skipped_already_processed' => $skippedAlreadyProcessed,
        ]);

        return $messages;
    }

    public function acknowledgeMessage(MailAccount $account, ?string $transportUid): void
    {
        if ($transportUid === null) {
            return;
        }

        // Record before PATCH so a mark-as-read 403 cannot cause endless re-fetch.
        $this->rememberProcessedMessageId($account, $transportUid);

        $creds = $this->entraResolver->resolve($account);
        $client = new MicrosoftGraphHttpClient(
            directoryTenantId: $creds['azure_tenant_id'],
            clientId: $creds['application_client_id'],
            clientSecret: $creds['client_secret'],
        );

        $mailboxUpn = rawurlencode($creds['mailbox_upn']);

        try {
            $client->patchJson("users/{$mailboxUpn}/messages/{$transportUid}", [
                'isRead' => true,
            ]);
        } catch (Throwable $e) {
            $message = $e->getMessage();
            if (str_contains($message, '403') || str_contains($message, 'ErrorAccessDenied')) {
                throw new RuntimeException(
                    $message.' Marking messages as read requires Microsoft Graph application permission Mail.ReadWrite (Mail.Read is not enough), then Grant admin consent. The message id was recorded locally so HelixDesk will not re-ingest it.',
                    0,
                    $e,
                );
            }

            throw $e;
        }
    }

    public function rememberProcessedMessageId(MailAccount $account, string $transportUid): void
    {
        $ids = $this->processedMessageIds($account);
        $ids[$transportUid] = true;

        if (count($ids) > self::MAX_TRACKED_PROCESSED_IDS) {
            $ids = array_slice($ids, -self::MAX_TRACKED_PROCESSED_IDS, null, true);
        }

        $syncState = is_array($account->sync_state) ? $account->sync_state : [];
        $syncState[self::SYNC_STATE_PROCESSED_IDS] = $ids;
        $account->sync_state = $syncState;
        $account->save();
    }

    /**
     * @return array<string, true>
     */
    private function processedMessageIds(MailAccount $account): array
    {
        $raw = $account->sync_state[self::SYNC_STATE_PROCESSED_IDS] ?? [];
        if (! is_array($raw)) {
            return [];
        }

        $ids = [];
        foreach ($raw as $key => $value) {
            if (is_string($key) && $key !== '' && $value) {
                $ids[$key] = true;
            } elseif (is_string($value) && $value !== '') {
                $ids[$value] = true;
            }
        }

        return $ids;
    }
}
