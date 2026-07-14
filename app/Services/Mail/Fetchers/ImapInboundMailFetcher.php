<?php

namespace App\Services\Mail\Fetchers;

use App\Models\MailAccount;
use App\Services\Mail\Contracts\InboundMailFetcher;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;

class ImapInboundMailFetcher implements InboundMailFetcher
{
    /**
     * @return list<array{raw: string, uid: string|null}>
     */
    public function fetchNewMessages(MailAccount $account): array
    {
        $client = $this->makeClient($account);

        $client->connect();
        $folder = $client->getFolder('INBOX');
        $messages = $folder->messages()->unseen()->get();

        $result = [];
        foreach ($messages as $message) {
            $headerRaw = $message->getHeader()?->raw ?? '';
            $bodyRaw = $message->getRawBody();
            if (! is_string($bodyRaw) || $bodyRaw === '') {
                continue;
            }

            $raw = $headerRaw.$bodyRaw;

            $result[] = [
                'raw' => $raw,
                'uid' => (string) $message->getUid(),
            ];
        }

        $client->disconnect();

        return $result;
    }

    public function acknowledgeMessage(MailAccount $account, ?string $transportUid): void
    {
        if ($transportUid === null) {
            return;
        }

        $client = $this->makeClient($account);

        $client->connect();
        $message = $client->getFolder('INBOX')->messages()->getMessageByUid((int) $transportUid);
        if ($message !== null) {
            $message->setFlag('Seen');
        }
        $client->disconnect();
    }

    private function makeClient(MailAccount $account): Client
    {
        $settings = is_array($account->connection_settings) ? $account->connection_settings : [];

        return (new ClientManager)->make([
            'host' => $settings['host'] ?? '',
            'port' => (int) ($settings['port'] ?? 993),
            'encryption' => $settings['encryption'] ?? 'ssl',
            'validate_cert' => (bool) ($settings['validate_cert'] ?? true),
            'username' => $account->imapUsername(),
            'password' => $account->imapPassword(),
            'protocol' => 'imap',
            'authentication' => null,
            'timeout' => max(5, (int) config('helixdesk.mail.connection_timeout_seconds', 15)),
        ]);
    }
}
