<?php

namespace App\Jobs;

use App\Models\MailAccount;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;

class PollMailAccountsJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        $intervalMinutes = max(1, (int) config('helixdesk.mail.poll_interval_minutes', 5));
        $dueBefore = Carbon::now()->subMinutes($intervalMinutes);

        MailAccount::query()
            ->withoutGlobalScopes()
            ->where('is_enabled', true)
            ->where(function ($query) use ($dueBefore): void {
                $query->whereNull('last_polled_at')
                    ->orWhere('last_polled_at', '<=', $dueBefore);
            })
            ->orderBy('id')
            ->pluck('id')
            ->each(fn (int $id) => PollMailAccountJob::dispatch($id));
    }
}
