<?php

namespace App\Filament\Agent\Resources\Organizations\Concerns;

use App\Jobs\SyncNextAction1IntegrationJob;
use App\Support\OrganizationIntegrationSettings;
use Illuminate\Support\Facades\Log;

trait HandlesAction1IntegrationSave
{
    protected ?string $savedAction1IntegrationId = null;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function validateAction1FormData(array $data): void
    {
        OrganizationIntegrationSettings::validateAction1FormDataForSave($data);
    }

    protected function queueAction1SyncIfReady(): void
    {
        $integrationId = $this->savedAction1IntegrationId;
        if (! is_string($integrationId) || $integrationId === '') {
            return;
        }

        $organization = $this->getRecord()->refresh();
        if ($organization->tenant_id === null) {
            Log::warning('Action1 sync not queued after save: organization has no tenant.', [
                'organization_id' => $organization->getKey(),
                'integration_id' => $integrationId,
            ]);

            return;
        }

        $entry = OrganizationIntegrationSettings::findEntryForOrganization(
            $organization,
            $integrationId,
        );

        if ($entry === null) {
            Log::warning('Action1 sync not queued after save: integration entry not found.', [
                'organization_id' => $organization->getKey(),
                'integration_id' => $integrationId,
            ]);

            return;
        }

        $config = is_array($entry['config'] ?? null) ? $entry['config'] : [];
        $skipReason = OrganizationIntegrationSettings::action1ConfigSyncSkipReason($config);
        if ($skipReason !== null) {
            Log::info('Action1 sync not queued after save.', [
                'organization_id' => $organization->getKey(),
                'integration_id' => $integrationId,
                'reason' => $skipReason,
            ]);

            return;
        }

        SyncNextAction1IntegrationJob::dispatch((int) $organization->getKey(), $integrationId);

        Log::info('Action1 sync queued after save.', [
            'organization_id' => $organization->getKey(),
            'integration_id' => $integrationId,
        ]);
    }
}
