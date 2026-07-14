<?php

namespace App\Services\Mail\Fetchers;

use App\Enums\MailInboundProtocol;
use App\Models\MailAccount;
use App\Services\Mail\Contracts\InboundMailFetcher;
use RuntimeException;

final class InboundMailFetcherFactory
{
    public function __construct(
        private readonly ImapInboundMailFetcher $imap,
        private readonly Pop3InboundMailFetcher $pop3,
        private readonly MicrosoftGraphInboundMailFetcher $graph,
    ) {}

    public function forAccount(MailAccount $account): InboundMailFetcher
    {
        return match ($account->protocol) {
            MailInboundProtocol::Imap => $this->imap,
            MailInboundProtocol::Pop3 => $this->pop3,
            MailInboundProtocol::MicrosoftGraph => $this->graph,
            MailInboundProtocol::GoogleGmailApi => throw new RuntimeException('Google Workspace Gmail API inbound mail is not implemented yet.'),
        };
    }
}
