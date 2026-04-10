<?php

namespace App\Jobs;

use App\Support\OrganizationIntegrationSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

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
            return;
        }

        SyncNextMicrosoftEntraIntegrationJob::dispatch($target->organizationId, $target->integrationId);
    }
}
