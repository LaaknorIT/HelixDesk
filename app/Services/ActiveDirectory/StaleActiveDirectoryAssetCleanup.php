<?php

namespace App\Services\ActiveDirectory;

use App\Models\Asset;
use App\Models\AssetExternalIdentifier;
use App\Models\AssetSourceRecord;
use App\Models\OrganizationIntegration;
use App\Support\DeviceSourceKey;
use App\Support\IntegrationType;
use Illuminate\Support\Collection;

/**
 * Finds and retires assets that only Active Directory knows about and that AD no longer reports as in use: every
 * AD record for the asset is gone, disabled or older than the integration's skip limit, and no other integration
 * has ever reported the device. Retiring only changes the lifecycle status, so it can be undone on the asset.
 */
final class StaleActiveDirectoryAssetCleanup
{
    private const LOG_NAME = 'assets';

    /**
     * @return Collection<int, Asset>
     */
    public function candidates(OrganizationIntegration $integration): Collection
    {
        $config = $integration->mergedConfig();

        $recordsByAsset = AssetSourceRecord::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $integration->tenant_id)
            ->where('organization_integration_id', $integration->getKey())
            ->where('source', DeviceSourceKey::ACTIVE_DIRECTORY)
            ->whereNotNull('asset_id')
            ->get()
            ->groupBy('asset_id');

        if ($recordsByAsset->isEmpty()) {
            return collect();
        }

        $staleAssetIds = $recordsByAsset
            ->filter(fn (Collection $records): bool => $records->every(fn (AssetSourceRecord $record): bool => $this->recordIsStale($config, $record)))
            ->keys()
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($staleAssetIds === []) {
            return collect();
        }

        $seenElsewhere = AssetSourceRecord::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $integration->tenant_id)
            ->whereIn('asset_id', $staleAssetIds)
            ->where(fn ($query) => $query
                ->where('source', '!=', DeviceSourceKey::ACTIVE_DIRECTORY)
                ->orWhere('organization_integration_id', '!=', $integration->getKey()))
            ->pluck('asset_id')
            ->merge(AssetExternalIdentifier::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $integration->tenant_id)
                ->whereIn('asset_id', $staleAssetIds)
                ->where('provider', '!=', IntegrationType::ACTIVE_DIRECTORY)
                ->pluck('asset_id'))
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->all();

        return Asset::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $integration->tenant_id)
            ->whereIn('id', array_diff($staleAssetIds, $seenElsewhere))
            ->where(fn ($query) => $query->whereNull('lifecycle_status')->orWhere('lifecycle_status', '!=', 'retired'))
            ->orderBy('name')
            ->get();
    }

    /**
     * @return int number of assets retired
     */
    public function retire(OrganizationIntegration $integration): int
    {
        $retired = 0;

        foreach ($this->candidates($integration) as $asset) {
            $previousStatus = $asset->lifecycle_status;
            $asset->lifecycle_status = 'retired';
            $asset->save();

            activity(self::LOG_NAME)
                ->performedOn($asset)
                ->event('retired')
                ->withProperties([
                    'organization_id' => $asset->organization_id,
                    'trigger' => 'active_directory_cleanup',
                    'integration_id' => (string) $integration->getKey(),
                    'attribute_changes' => [
                        'attributes' => ['lifecycle_status' => 'retired'],
                        'old' => ['lifecycle_status' => $previousStatus],
                    ],
                ])
                ->log('Asset retired: only Active Directory reported it, and AD no longer reports it as in use.');

            $retired++;
        }

        return $retired;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function recordIsStale(array $config, AssetSourceRecord $record): bool
    {
        if ($record->gone_at !== null) {
            return true;
        }

        $metadata = (array) ($record->metadata_json ?? []);

        return ActiveDirectoryInventoryService::skipReason($config, $record->enabled, $record->last_activity_at, [
            'is_domain_controller' => $metadata['is_domain_controller'] ?? false,
            'when_created' => $metadata['when_created'] ?? null,
        ]) !== null;
    }
}
