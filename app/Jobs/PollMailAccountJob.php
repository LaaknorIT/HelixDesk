<?php

namespace App\Jobs;

use App\Models\MailAccount;
use App\Services\Mail\Fetchers\InboundMailFetcherFactory;
use App\Services\Mail\InboundMailIngestionService;
use App\Services\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Throwable;

class PollMailAccountJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $mailAccountId) {}

    public function handle(
        TenantContext $tenantContext,
        InboundMailFetcherFactory $fetcherFactory,
        InboundMailIngestionService $ingestionService,
    ): void {
        /** @var MailAccount|null $account */
        $account = MailAccount::query()->withoutGlobalScopes()->find($this->mailAccountId);

        if ($account === null || ! $account->is_enabled) {
            return;
        }

        $tenantContext->setTenantId((int) $account->tenant_id);
        setPermissionsTeamId((int) $account->tenant_id);

        try {
            $fetcher = $fetcherFactory->forAccount($account);

            foreach ($fetcher->fetchNewMessages($account) as $item) {
                $ingestionService->ingest($account, $item['raw'], $item['uid']);
                $fetcher->acknowledgeMessage($account, $item['uid']);
            }

            $account->last_polled_at = Carbon::now();
            $account->last_poll_error = null;
            $account->save();
        } catch (Throwable $e) {
            $account->last_poll_error = $e->getMessage();
            $account->save();

            throw $e;
        } finally {
            $tenantContext->setTenantId(null);
            setPermissionsTeamId(null);
        }
    }
}
