<?php

namespace App\Jobs;

use App\Models\Organization;
use App\Services\MicrosoftEntra\MicrosoftEntraOrganizationSyncService;
use App\Support\EntraSyncTarget;
use App\Support\OrganizationIntegrationSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncNextMicrosoftEntraIntegrationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public function __construct(
        public int $organizationId,
        public string $integrationId,
    ) {
        $this->timeout = max(
            60,
            (int) config('helixdesk_integrations.entra.sync_job_timeout_seconds', 600),
        );
    }

    public function handle(MicrosoftEntraOrganizationSyncService $syncService): void
    {
        $organization = Organization::query()->withoutGlobalScopes()->find($this->organizationId);
        if ($organization === null) {
            Log::warning('Microsoft Entra sync skipped: organization not found.', [
                'organization_id' => $this->organizationId,
                'integration_id' => $this->integrationId,
            ]);

            return;
        }

        if ($organization->tenant_id === null) {
            Log::warning('Microsoft Entra sync skipped: organization has no tenant.', [
                'organization_id' => $this->organizationId,
                'integration_id' => $this->integrationId,
            ]);

            return;
        }

        $entry = OrganizationIntegrationSettings::findEntryForOrganization(
            $organization,
            $this->integrationId,
        );

        if ($entry === null) {
            Log::warning('Microsoft Entra sync skipped: integration entry not found.', [
                'organization_id' => $this->organizationId,
                'integration_id' => $this->integrationId,
            ]);

            return;
        }

        $config = is_array($entry['config'] ?? null) ? $entry['config'] : [];
        $skipReason = OrganizationIntegrationSettings::entraConfigSyncSkipReason($config);
        if ($skipReason !== null) {
            Log::warning('Microsoft Entra sync skipped: integration is not ready.', [
                'organization_id' => $this->organizationId,
                'integration_id' => $this->integrationId,
                'tenant_id' => $organization->tenant_id,
                'reason' => $skipReason,
            ]);

            return;
        }

        $target = new EntraSyncTarget(
            tenantId: (int) $organization->tenant_id,
            organizationId: (int) $organization->getKey(),
            integrationId: (string) $entry['id'],
            config: $config,
        );

        Log::info('Microsoft Entra sync started.', [
            'tenant_id' => $target->tenantId,
            'organization_id' => $target->organizationId,
            'integration_id' => $target->integrationId,
            'scopes' => $target->config['scopes'] ?? [],
        ]);

        try {
            $syncService->sync($target);

            Log::info('Microsoft Entra sync completed.', [
                'tenant_id' => $target->tenantId,
                'organization_id' => $target->organizationId,
                'integration_id' => $target->integrationId,
            ]);
        } catch (Throwable $e) {
            Log::error('Microsoft Entra sync failed', [
                'tenant_id' => $target->tenantId,
                'organization_id' => $target->organizationId,
                'integration_id' => $target->integrationId,
                'exception_class' => $e::class,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $syncService->recordFailure($target, $e);
        }
    }
}
