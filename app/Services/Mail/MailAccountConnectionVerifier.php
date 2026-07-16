<?php

namespace App\Services\Mail;

use App\Enums\MailInboundProtocol;
use App\Models\MailAccount;
use App\Services\MicrosoftEntra\MicrosoftGraphHttpClient;
use App\Support\MailAccountEntraResolver;
use RuntimeException;
use Throwable;
use Webklex\PHPIMAP\ClientManager;

class MailAccountConnectionVerifier
{
    public function __construct(
        private readonly MailAccountEntraResolver $entraResolver,
        private readonly MailInboundLogger $logger,
    ) {}

    public function verify(MailAccount $account): MailAccountConnectionVerifyResult
    {
        try {
            $result = match ($account->protocol) {
                MailInboundProtocol::Imap => $this->verifyImap($account),
                MailInboundProtocol::Pop3 => $this->verifyPop3($account),
                MailInboundProtocol::MicrosoftGraph => $this->verifyMicrosoftGraph($account),
                MailInboundProtocol::GoogleGmailApi => new MailAccountConnectionVerifyResult(
                    ok: false,
                    message: 'Google Workspace Gmail API inbound mail is not implemented yet.',
                ),
            };
        } catch (Throwable $e) {
            $this->logger->exception($account, 'Mail account connection verify failed', $e);
            $result = new MailAccountConnectionVerifyResult(
                ok: false,
                message: $e->getMessage(),
            );
        }

        if ($result->ok) {
            $this->logger->info($account, 'Mail account connection verify succeeded', [
                'details' => $result->details,
            ]);
        } else {
            $this->logger->warning($account, 'Mail account connection verify failed', [
                'message' => $result->message,
                'details' => $result->details,
            ]);
        }

        return $result;
    }

    private function verifyImap(MailAccount $account): MailAccountConnectionVerifyResult
    {
        $settings = is_array($account->connection_settings) ? $account->connection_settings : [];

        $client = (new ClientManager)->make([
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

        $client->connect();
        $folder = $client->getFolder('INBOX');
        if ($folder === null) {
            $client->disconnect();
            throw new RuntimeException('IMAP connected but INBOX folder was not found.');
        }
        $client->disconnect();

        return new MailAccountConnectionVerifyResult(
            ok: true,
            message: 'IMAP connection succeeded and INBOX is accessible.',
        );
    }

    private function verifyPop3(MailAccount $account): MailAccountConnectionVerifyResult
    {
        $settings = is_array($account->connection_settings) ? $account->connection_settings : [];
        $host = (string) ($settings['host'] ?? '');
        $port = (int) ($settings['port'] ?? 995);
        $username = (string) ($settings['username'] ?? '');
        $password = (string) ($settings['password'] ?? '');
        $encryption = (string) ($settings['encryption'] ?? 'ssl');

        $transport = $encryption === 'ssl' ? 'ssl://' : 'tcp://';
        $timeout = max(5, (int) config('helixdesk.mail.connection_timeout_seconds', 15));
        $socket = @stream_socket_client($transport.$host.':'.$port, $errno, $errstr, $timeout);

        if ($socket === false) {
            throw new RuntimeException('POP3 connection failed: '.$errstr.' ('.$errno.')');
        }

        stream_set_timeout($socket, 30);
        $this->expectPop3Response($socket, '+OK');

        if ($encryption === 'tls') {
            $this->pop3Command($socket, 'STLS');
            stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        }

        $this->pop3Command($socket, 'USER '.$username);
        $this->pop3Command($socket, 'PASS '.$password);
        $stat = $this->pop3Command($socket, 'STAT');
        $this->pop3Command($socket, 'QUIT');
        fclose($socket);

        $parts = preg_split('/\s+/', trim($stat));
        $messageCount = is_array($parts) && isset($parts[1]) ? (int) $parts[1] : 0;

        return new MailAccountConnectionVerifyResult(
            ok: true,
            message: "POP3 connection succeeded. Mailbox reports {$messageCount} message(s).",
            details: ['message_count' => $messageCount],
        );
    }

    private function verifyMicrosoftGraph(MailAccount $account): MailAccountConnectionVerifyResult
    {
        $creds = $this->entraResolver->resolve($account);
        $client = new MicrosoftGraphHttpClient(
            directoryTenantId: $creds['azure_tenant_id'],
            clientId: $creds['application_client_id'],
            clientSecret: $creds['client_secret'],
        );

        $mailboxUpn = $creds['mailbox_upn'];
        $encodedUpn = rawurlencode($mailboxUpn);

        try {
            $inbox = $client->getJson("users/{$encodedUpn}/mailFolders/inbox", [
                '$select' => 'id,displayName,totalItemCount,unreadItemCount',
            ]);
        } catch (Throwable $e) {
            $message = $e->getMessage();
            if (str_contains($message, '403') || str_contains($message, 'ErrorAccessDenied')) {
                $message .= ' Grant Microsoft Graph application permissions Mail.Read and Mail.Send on the Entra app registration, then Grant admin consent for the organization. Enable the Mail (shared mailboxes) capability on the Entra integration in HelixDesk to see the full checklist.';
            }

            throw new RuntimeException($message, 0, $e);
        }

        $total = (int) ($inbox['totalItemCount'] ?? 0);
        $unread = (int) ($inbox['unreadItemCount'] ?? 0);

        $message = "Microsoft Graph access succeeded for {$mailboxUpn}. Inbox has {$total} message(s), {$unread} unread.";
        if ($total > 0 && $unread === 0) {
            $message .= ' Inbound polling only fetches unread messages (isRead eq false).';
        }

        return new MailAccountConnectionVerifyResult(
            ok: true,
            message: $message,
            details: [
                'mailbox_upn' => $mailboxUpn,
                'total_item_count' => $total,
                'unread_item_count' => $unread,
            ],
        );
    }

    /**
     * @param  resource  $socket
     */
    private function pop3Command($socket, string $command): string
    {
        fwrite($socket, $command."\r\n");

        $line = fgets($socket);
        if ($line === false || ! str_starts_with($line, '+OK')) {
            throw new RuntimeException('Unexpected POP3 response: '.trim((string) $line));
        }

        return trim($line);
    }

    /**
     * @param  resource  $socket
     */
    private function expectPop3Response($socket, string $prefix): void
    {
        $line = fgets($socket);
        if ($line === false || ! str_starts_with($line, $prefix)) {
            throw new RuntimeException('Unexpected POP3 response: '.trim((string) $line));
        }
    }
}
