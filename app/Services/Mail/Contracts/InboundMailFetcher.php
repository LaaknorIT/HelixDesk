<?php

namespace App\Services\Mail\Contracts;

use App\Models\MailAccount;

interface InboundMailFetcher
{
    /**
     * @return list<array{raw: string, uid: string|null}>
     */
    public function fetchNewMessages(MailAccount $account): array;

    public function acknowledgeMessage(MailAccount $account, ?string $transportUid): void;
}
