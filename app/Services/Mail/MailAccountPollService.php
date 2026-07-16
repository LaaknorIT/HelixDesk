<?php

namespace App\Services\Mail;

use App\Enums\InboundMailIngestOutcome;
use App\Models\MailAccount;
use App\Services\Mail\Fetchers\InboundMailFetcherFactory;
use App\Services\TenantContext;
use Illuminate\Support\Carbon;
use Throwable;

class MailAccountPollService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly InboundMailFetcherFactory $fetcherFactory,
        private readonly InboundMailIngestionService $ingestionService,
        private readonly MailInboundLogger $logger,
    ) {}

    public function poll(MailAccount $account): MailAccountPollResult
    {
        if (! $account->is_enabled) {
            $this->logger->warning($account, 'Skipping poll for disabled mail account');

            return new MailAccountPollResult(error: 'Mail account is disabled.');
        }

        $previousTenantId = $this->tenantContext->tenantId();
        $this->tenantContext->setTenantId((int) $account->tenant_id);
        setPermissionsTeamId((int) $account->tenant_id);

        $fetched = 0;
        $ingested = 0;
        $skipped = 0;
        $duplicates = 0;
        $acknowledgeWarnings = [];

        try {
            $this->logger->info($account, 'Starting mail account poll');

            $fetcher = $this->fetcherFactory->forAccount($account);
            $messages = $fetcher->fetchNewMessages($account);
            $fetched = count($messages);

            foreach ($messages as $item) {
                $outcome = $this->ingestionService->ingest($account, $item['raw'], $item['uid']);

                match ($outcome) {
                    InboundMailIngestOutcome::Ingested => $ingested++,
                    InboundMailIngestOutcome::Duplicate => $duplicates++,
                    InboundMailIngestOutcome::SkippedEmptyFrom,
                    InboundMailIngestOutcome::SkippedNoQueue => $skipped++,
                };

                if (! $outcome->shouldAcknowledge()) {
                    continue;
                }

                try {
                    $fetcher->acknowledgeMessage($account, $item['uid']);
                } catch (Throwable $e) {
                    // Ingest/dedup already succeeded; do not fail the poll or re-process forever.
                    $acknowledgeWarnings[] = $e->getMessage();
                    $this->logger->warning($account, 'Failed to acknowledge inbound message after processing', [
                        'transport_uid' => $item['uid'],
                        'outcome' => $outcome->value,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $account->refresh();
            $account->last_polled_at = Carbon::now();

            if ($acknowledgeWarnings !== []) {
                $account->last_poll_error = 'Processed mail, but acknowledge failed: '.$acknowledgeWarnings[0];
            } else {
                $account->last_poll_error = null;
            }

            $account->save();

            $result = new MailAccountPollResult(
                fetched: $fetched,
                ingested: $ingested,
                skipped: $skipped,
                duplicates: $duplicates,
            );

            $this->logger->info($account, 'Mail account poll completed', [
                'fetched' => $fetched,
                'ingested' => $ingested,
                'duplicates' => $duplicates,
                'skipped' => $skipped,
                'acknowledge_warnings' => count($acknowledgeWarnings),
            ]);

            return $result;
        } catch (Throwable $e) {
            $account->last_poll_error = $e->getMessage();
            $account->save();

            $this->logger->exception($account, 'Mail account poll failed', $e, [
                'fetched' => $fetched,
                'ingested' => $ingested,
                'duplicates' => $duplicates,
                'skipped' => $skipped,
            ]);

            return new MailAccountPollResult(
                fetched: $fetched,
                ingested: $ingested,
                skipped: $skipped,
                duplicates: $duplicates,
                error: $e->getMessage(),
            );
        } finally {
            $this->tenantContext->setTenantId($previousTenantId);
            setPermissionsTeamId($previousTenantId);
        }
    }
}
