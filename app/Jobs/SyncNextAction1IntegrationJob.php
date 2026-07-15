<?php

namespace App\Jobs;

use App\Models\Organization;
use App\Repositories\OrganizationIntegrationRepository;
use App\Services\Action1\Action1OrganizationSyncService;
use App\Support\Action1SyncTarget;
use App\Support\OrganizationIntegrationSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncNextAction1IntegrationJob implements ShouldQueue
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
            (int) config('helixdesk_integrations.action1.sync_job_timeout_seconds', 600),
        );
    }

    public function handle(Action1OrganizationSyncService $syncService): void
    {
        $organization = Organization::query()->withoutGlobalScopes()->find($this->organizationId);
        if ($organization === null) {
            Log::warning('Action1 sync skipped: organization not found.', [
                'organization_id' => $this->organizationId,
                'integration_id' => $this->integrationId,
            ]);

            return;
        }

        if ($organization->tenant_id === null) {
            Log::warning('Action1 sync skipped: organization has no tenant.', [
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
            Log::warning('Action1 sync skipped: integration entry not found.', [
                'organization_id' => $this->organizationId,
                'integration_id' => $this->integrationId,
            ]);

            return;
        }

        $config = is_array($entry['config'] ?? null) ? $entry['config'] : [];
        $skipReason = OrganizationIntegrationSettings::action1ConfigSyncSkipReason($config);
        if ($skipReason !== null) {
            Log::warning('Action1 sync skipped: integration is not ready.', [
                'organization_id' => $this->organizationId,
                'integration_id' => $this->integrationId,
                'tenant_id' => $organization->tenant_id,
                'reason' => $skipReason,
            ]);

            return;
        }

        $integration = app(OrganizationIntegrationRepository::class)
            ->findModel($organization, $this->integrationId);

        $target = new Action1SyncTarget(
            tenantId: (int) $organization->tenant_id,
            organizationId: (int) $organization->getKey(),
            integrationId: (string) $entry['id'],
            config: $config,
            credentials: $integration?->mergedCredentials() ?? [],
            apiBaseUrl: $integration?->api_base_url,
        );

        Log::info('Action1 sync started.', [
            'tenant_id' => $target->tenantId,
            'organization_id' => $target->organizationId,
            'integration_id' => $target->integrationId,
            'api_base_url' => $target->apiBaseUrl,
            'scopes' => $target->config['scopes'] ?? [],
        ]);

        try {
            $syncService->sync($target);

            Log::info('Action1 sync completed.', [
                'tenant_id' => $target->tenantId,
                'organization_id' => $target->organizationId,
                'integration_id' => $target->integrationId,
            ]);
        } catch (Throwable $e) {
            Log::error('Action1 sync failed', [
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
