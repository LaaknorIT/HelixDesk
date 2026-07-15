<?php

namespace App\Filament\Agent\Resources\Organizations\Concerns;

use App\Jobs\SyncNextMicrosoftEntraIntegrationJob;
use App\Support\OrganizationIntegrationSettings;
use Illuminate\Support\Facades\Log;

trait HandlesMicrosoftEntraIntegrationSave
{
    protected ?string $savedMicrosoftEntraIntegrationId = null;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function validateMicrosoftEntraFormData(array $data): void
    {
        OrganizationIntegrationSettings::validateEntraFormDataForSave($data);
    }

    protected function queueMicrosoftEntraSyncIfReady(): void
    {
        $integrationId = $this->savedMicrosoftEntraIntegrationId;
        if (! is_string($integrationId) || $integrationId === '') {
            return;
        }

        $organization = $this->getRecord()->refresh();
        if ($organization->tenant_id === null) {
            Log::warning('Microsoft Entra sync not queued after save: organization has no tenant.', [
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
            Log::warning('Microsoft Entra sync not queued after save: integration entry not found.', [
                'organization_id' => $organization->getKey(),
                'integration_id' => $integrationId,
            ]);

            return;
        }

        $config = is_array($entry['config'] ?? null) ? $entry['config'] : [];
        $skipReason = OrganizationIntegrationSettings::entraConfigSyncSkipReason($config);
        if ($skipReason !== null) {
            Log::info('Microsoft Entra sync not queued after save.', [
                'organization_id' => $organization->getKey(),
                'integration_id' => $integrationId,
                'reason' => $skipReason,
            ]);

            return;
        }

        SyncNextMicrosoftEntraIntegrationJob::dispatch((int) $organization->getKey(), $integrationId);

        Log::info('Microsoft Entra sync queued after save.', [
            'organization_id' => $organization->getKey(),
            'integration_id' => $integrationId,
        ]);
    }
}
