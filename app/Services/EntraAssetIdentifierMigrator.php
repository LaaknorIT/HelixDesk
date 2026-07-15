<?php

namespace App\Services;

use App\Enums\AssetExternalIdentifierType;
use App\Models\Asset;
use App\Models\AssetExternalIdentifier;
use App\Models\OrganizationIntegration;
use App\Support\AssetIdentifierHelper;
use App\Support\IntegrationType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

final class EntraAssetIdentifierMigrator
{
    public function migrateAll(): int
    {
        $migrated = 0;

        $entraIntegrations = OrganizationIntegration::query()
            ->withoutGlobalScopes()
            ->where('type', IntegrationType::MICROSOFT_ENTRA)
            ->get()
            ->keyBy(fn (OrganizationIntegration $integration): string => (string) $integration->organization_id);

        Asset::query()
            ->withoutGlobalScopes()
            ->whereNotNull('asset_identifier')
            ->where('asset_identifier', '!=', '')
            ->orderBy('id')
            ->chunkById(100, function ($assets) use ($entraIntegrations, &$migrated): void {
                foreach ($assets as $asset) {
                    if ($this->migrateAsset($asset, $entraIntegrations)) {
                        $migrated++;
                    }
                }
            });

        return $migrated;
    }

    /**
     * @param  Collection<string, OrganizationIntegration>  $entraIntegrations
     */
    public function migrateAsset(Asset $asset, Collection $entraIntegrations): bool
    {
        $identifier = trim((string) $asset->asset_identifier);
        if ($identifier === '' || ! AssetIdentifierHelper::isGuid($identifier)) {
            return false;
        }

        $integration = $entraIntegrations->get((string) $asset->organization_id);
        if ($integration === null) {
            return false;
        }

        $existing = AssetExternalIdentifier::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $asset->tenant_id)
            ->where('asset_id', $asset->getKey())
            ->where('provider', IntegrationType::MICROSOFT_ENTRA)
            ->where('identifier_type', AssetExternalIdentifierType::EntraAzureAdDeviceId)
            ->where('external_id', $identifier)
            ->exists();

        if ($existing) {
            return false;
        }

        if ($this->findAmbiguousSerialMatches($asset)->isNotEmpty()) {
            Log::warning('Skipped ambiguous Entra asset identifier migration.', [
                'asset_id' => $asset->getKey(),
                'asset_identifier' => $identifier,
            ]);

            return false;
        }

        AssetExternalIdentifier::query()->updateOrCreate(
            [
                'tenant_id' => $asset->tenant_id,
                'asset_id' => $asset->getKey(),
                'provider' => IntegrationType::MICROSOFT_ENTRA,
                'identifier_type' => AssetExternalIdentifierType::EntraAzureAdDeviceId,
                'external_id' => $identifier,
            ],
            [
                'organization_id' => $asset->organization_id,
                'organization_integration_id' => $integration->getKey(),
                'is_primary' => true,
                'last_seen_at' => now(),
            ],
        );

        $updates = [];
        if ($asset->primary_hostname === null && AssetIdentifierHelper::looksLikeHostname($identifier)) {
            $updates['primary_hostname'] = $identifier;
        }

        $updates['asset_identifier'] = AssetIdentifierHelper::generatedAssetTag((int) $asset->getKey());
        $asset->update($updates);

        return true;
    }

    /**
     * @return Collection<int, Asset>
     */
    private function findAmbiguousSerialMatches(Asset $asset): Collection
    {
        $serial = trim((string) ($asset->serial_number ?? ''));
        if ($serial === '') {
            return collect();
        }

        return Asset::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $asset->tenant_id)
            ->where('organization_id', $asset->organization_id)
            ->whereRaw('LOWER(TRIM(serial_number)) = ?', [strtolower($serial)])
            ->whereKeyNot($asset->getKey())
            ->get();
    }
}
