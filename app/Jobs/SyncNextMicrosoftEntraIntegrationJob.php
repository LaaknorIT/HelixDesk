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

    public function __construct(
        public int $organizationId,
        public string $integrationId,
    ) {}

    public function handle(MicrosoftEntraOrganizationSyncService $syncService): void
    {
        $organization = Organization::query()->withoutGlobalScopes()->find($this->organizationId);
        if ($organization === null || $organization->tenant_id === null) {
            return;
        }

        $entry = OrganizationIntegrationSettings::findEntry(
            is_array($organization->organizational_settings) ? $organization->organizational_settings : [],
            $this->integrationId,
        );

        if ($entry === null) {
            return;
        }

        $config = is_array($entry['config'] ?? null) ? $entry['config'] : [];
        if (! OrganizationIntegrationSettings::entraConfigReadyForSync($config)) {
            return;
        }

        $target = new EntraSyncTarget(
            tenantId: (int) $organization->tenant_id,
            organizationId: (int) $organization->getKey(),
            integrationId: (string) $entry['id'],
            config: $config,
        );

        try {
            $syncService->sync($target);
        } catch (Throwable $e) {
            Log::error('Microsoft Entra sync failed', [
                'tenant_id' => $target->tenantId,
                'organization_id' => $target->organizationId,
                'integration_id' => $target->integrationId,
                'exception' => $e->getMessage(),
            ]);
            $syncService->recordFailure($target, $e);
        }
    }
}
