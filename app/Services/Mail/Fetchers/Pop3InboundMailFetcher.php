<?php

namespace App\Services\Mail\Fetchers;

use App\Models\MailAccount;
use App\Services\Mail\Contracts\InboundMailFetcher;
use RuntimeException;

class Pop3InboundMailFetcher implements InboundMailFetcher
{
    /**
     * @return list<array{raw: string, uid: string|null}>
     */
    public function fetchNewMessages(MailAccount $account): array
    {
        $settings = $account->connection_settings ?? [];
        $host = (string) ($settings['host'] ?? '');
        $port = (int) ($settings['port'] ?? 995);
        $username = (string) ($settings['username'] ?? '');
        $password = (string) ($settings['password'] ?? '');
        $encryption = (string) ($settings['encryption'] ?? 'ssl');

        $transport = $encryption === 'ssl' ? 'ssl://' : 'tcp://';
        $timeout = max(5, (int) config('helixdesk.mail.connection_timeout_seconds', 15));
        $socket = @stream_socket_client($transport.$host.':'.$port, $errno, $errstr, $timeout);
        if ($socket === false) {
            throw new RuntimeException('POP3 connection failed: '.$errstr);
        }

        stream_set_timeout($socket, 30);
        $this->expectResponse($socket, '+OK');

        if ($encryption === 'tls') {
            $this->command($socket, 'STLS');
            stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        }

        $this->command($socket, 'USER '.$username);
        $this->command($socket, 'PASS '.$password);

        $uidlResponse = $this->command($socket, 'UIDL');
        $known = is_array($account->sync_state['uidl'] ?? null) ? $account->sync_state['uidl'] : [];

        $messages = [];
        $newUidl = $known;

        foreach (array_slice(explode("\n", $uidlResponse), 1) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '.')) {
                continue;
            }

            $parts = preg_split('/\s+/', $line, 2);
            if (! is_array($parts) || count($parts) < 2) {
                continue;
            }

            [$msgNum, $uidl] = $parts;
            $newUidl[$uidl] = true;

            if (isset($known[$uidl])) {
                continue;
            }

            $raw = $this->command($socket, 'RETR '.$msgNum);
            $messages[] = [
                'raw' => $this->stripPop3DotStuffing($raw),
                'uid' => $uidl,
            ];
        }

        $account->sync_state = array_merge($account->sync_state ?? [], ['uidl' => $newUidl]);
        $account->save();

        $this->command($socket, 'QUIT');
        fclose($socket);

        return $messages;
    }

    public function acknowledgeMessage(MailAccount $account, ?string $transportUid): void
    {
        // POP3 messages are not deleted on fetch; UIDL tracking prevents reprocessing.
    }

    /**
     * @param  resource  $socket
     */
    private function command($socket, string $command): string
    {
        fwrite($socket, $command."\r\n");

        return $this->readMultiline($socket);
    }

    /**
     * @param  resource  $socket
     */
    private function expectResponse($socket, string $prefix): void
    {
        $line = fgets($socket);
        if ($line === false || ! str_starts_with($line, $prefix)) {
            throw new RuntimeException('Unexpected POP3 response: '.(string) $line);
        }
    }

    /**
     * @param  resource  $socket
     */
    private function readMultiline($socket): string
    {
        $buffer = '';
        while (($line = fgets($socket)) !== false) {
            $buffer .= $line;
            if (preg_match('/^\+OK\b/', $line) === 1 && ! str_contains($line, 'octets')) {
                break;
            }
            if (preg_match('/^\.\r?\n$/', $line) === 1) {
                break;
            }
        }

        return $buffer;
    }

    private function stripPop3DotStuffing(string $raw): string
    {
        $lines = preg_split("/\r\n|\n|\r/", $raw) ?: [];
        $body = [];
        foreach ($lines as $line) {
            if (str_starts_with($line, '.')) {
                $line = substr($line, 1);
            }
            if ($line === '.') {
                break;
            }
            $body[] = $line;
        }

        return implode("\r\n", $body);
    }
}
