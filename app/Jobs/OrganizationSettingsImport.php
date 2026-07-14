<?php

namespace App\Jobs;

use App\Support\OrganizationIntegrationSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Dispatched on a schedule when at least one Microsoft Entra integration is due for import.
 * Queues a targeted sync for one organization/integration pair.
 */
class OrganizationSettingsImport implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function handle(): void
    {
        $target = OrganizationIntegrationSettings::firstMicrosoftEntraIntegrationDueForImport();
        if ($target === null) {
            Log::debug('Microsoft Entra import scheduler: no integration due for import.');

            return;
        }

        Log::info('Microsoft Entra import scheduler: queuing sync job.', [
            'organization_id' => $target->organizationId,
            'integration_id' => $target->integrationId,
            'tenant_id' => $target->tenantId,
        ]);

        SyncNextMicrosoftEntraIntegrationJob::dispatch($target->organizationId, $target->integrationId);
    }
}
