<?php

namespace App\Jobs;

use App\Support\IntegrationType;
use App\Support\OrganizationIntegrationSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Dispatched on a schedule when at least one asset-sync integration is due for import.
 * Queues a targeted sync for one organization/integration pair.
 */
class OrganizationSettingsImport implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function handle(): void
    {
        $due = OrganizationIntegrationSettings::firstAssetSyncIntegrationDueForImport();
        if ($due === null) {
            Log::debug('Integration import scheduler: no asset-sync integration due for import.');

            return;
        }

        $target = $due['target'];

        Log::info('Integration import scheduler: queuing sync job.', [
            'integration_type' => $due['type'],
            'organization_id' => $target->organizationId,
            'integration_id' => $target->integrationId,
            'tenant_id' => $target->tenantId,
        ]);

        if ($due['type'] === IntegrationType::ACTION1) {
            SyncNextAction1IntegrationJob::dispatch($target->organizationId, $target->integrationId);

            return;
        }

        SyncNextMicrosoftEntraIntegrationJob::dispatch($target->organizationId, $target->integrationId);
    }
}
