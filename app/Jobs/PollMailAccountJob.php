<?php

namespace App\Jobs;

use App\Models\MailAccount;
use App\Services\Mail\MailAccountPollService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

class PollMailAccountJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $mailAccountId) {}

    public function handle(MailAccountPollService $pollService): void
    {
        /** @var MailAccount|null $account */
        $account = MailAccount::query()->withoutGlobalScopes()->find($this->mailAccountId);

        if ($account === null || ! $account->is_enabled) {
            return;
        }

        $result = $pollService->poll($account);

        if (! $result->succeeded()) {
            throw new RuntimeException($result->error ?? 'Mail account poll failed.');
        }
    }
}
