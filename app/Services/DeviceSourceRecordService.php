<?php

namespace App\Services;

use App\Data\AssetProviderSnapshot;
use App\Enums\DeviceClass;
use App\Models\Asset;
use App\Models\AssetSourceRecord;
use App\Support\AssetIdentityNormalizer;
use Illuminate\Support\Carbon;

/**
 * Stores what each device source reports about a device (asset_source_records), so the coverage report can
 * compare sources without depending on how records were merged into assets.
 */
final class DeviceSourceRecordService
{
    /**
     * Upserts the snapshot's source record. $asset is the asset the snapshot was reconciled to, or null when
     * the record is not linked (for example an ambiguous match).
     */
    public function record(AssetProviderSnapshot $snapshot, ?Asset $asset): ?AssetSourceRecord
    {
        $data = $snapshot->sourceRecord;
        if ($data === null || trim($data->providerRecordId) === '') {
            return null;
        }

        $now = now();

        $record = AssetSourceRecord::query()
            ->withoutGlobalScopes()
            ->firstOrNew([
                'tenant_id' => $snapshot->tenantId,
                'organization_integration_id' => $snapshot->organizationIntegrationId,
                'source' => $data->source,
                'provider_record_id' => $data->providerRecordId,
            ]);

        $macAddresses = array_values(array_unique(array_filter(array_map(
            AssetIdentityNormalizer::normalizeMac(...),
            $data->macAddresses,
        ))));

        $metadata = $data->metadata;
        if ($data->discoveredOnly) {
            $metadata[AssetSourceRecord::METADATA_DISCOVERED_ONLY] = true;
        }

        $record->fill([
            // A linked record follows its device, which may live below the integration's organization.
            'organization_id' => $asset !== null ? $asset->organization_id : $snapshot->organizationId,
            'asset_id' => $asset?->getKey(),
            'display_name' => $data->displayName,
            'hostname' => AssetIdentityNormalizer::normalizeHostname($data->hostname ?? $data->fqdn),
            'fqdn' => $data->fqdn !== null && trim($data->fqdn) !== '' ? mb_strtolower(trim($data->fqdn)) : null,
            'serial_number' => AssetIdentityNormalizer::normalizeSerial($data->serialNumber),
            'mac_addresses' => $macAddresses !== [] ? $macAddresses : null,
            'operating_system' => $data->operatingSystem,
            'device_class' => $data->deviceClass ?? DeviceClass::fromOperatingSystem($data->operatingSystem),
            'enabled' => $data->enabled,
            'last_activity_at' => $data->lastActivityAt,
            'last_reported_at' => $now,
            'gone_at' => null,
            'metadata_json' => $metadata !== [] ? $metadata : null,
        ]);

        if (! $record->exists) {
            $record->first_seen_at = $now;
        }

        $record->save();

        return $record;
    }

    /**
     * After a complete sync of one source for one integration, marks records that the sync did not report
     * as gone. Call it only when the whole provider listing was read; an interrupted sync must not remove anything.
     */
    public function markMissingAsGone(int $tenantId, string $organizationIntegrationId, string $source, Carbon $syncStartedAt): int
    {
        return AssetSourceRecord::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('organization_integration_id', $organizationIntegrationId)
            ->where('source', $source)
            ->whereNull('gone_at')
            ->where(fn ($query) => $query
                ->whereNull('last_reported_at')
                ->orWhere('last_reported_at', '<', $syncStartedAt))
            ->update(['gone_at' => now(), 'updated_at' => now()]);
    }
}
