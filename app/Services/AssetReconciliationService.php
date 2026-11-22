<?php

namespace App\Services;

use App\Data\AssetProviderSnapshot;
use App\Data\AssetReconciliationResult;
use App\Enums\AssetExternalIdentifierType;
use App\Enums\AssetIdentityAmbiguityStatus;
use App\Enums\AssetIdentityResolutionStatus;
use App\Models\Asset;
use App\Models\AssetExternalIdentifier;
use App\Models\AssetIdentityAmbiguity;
use App\Models\AssetType;
use App\Support\AssetIdentifierHelper;
use App\Support\AssetIdentityNormalizer;
use App\Support\IntegrationType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

final class AssetReconciliationService
{
    public function __construct(
        private readonly AssetAttributeMerger $attributeMerger,
        private readonly DeviceSourceRecordService $deviceSourceRecordService,
    ) {}

    /**
     * Resolves the snapshot to an asset (or an ambiguity) and stores the snapshot's device source record,
     * linked to the resolved asset.
     */
    public function reconcile(AssetProviderSnapshot $snapshot): AssetReconciliationResult
    {
        $result = $this->resolve($snapshot);

        $this->deviceSourceRecordService->record($snapshot, $result->ambiguous ? null : $result->asset);

        return $result;
    }

    private function resolve(AssetProviderSnapshot $snapshot): AssetReconciliationResult
    {
        $matchedAsset = $this->findHardMatchedAsset($snapshot);

        if ($matchedAsset === null) {
            $softMatch = $this->findSoftMatchedAsset($snapshot);
            if ($softMatch['ambiguous']) {
                return $this->handleAmbiguity($snapshot, $softMatch['candidates']);
            }

            $matchedAsset = $softMatch['asset'];
        }

        if ($matchedAsset !== null) {
            $this->applySnapshotToAsset($snapshot, $matchedAsset);

            return new AssetReconciliationResult(
                asset: $matchedAsset,
                created: false,
                matched: true,
            );
        }

        $asset = $this->createAssetFromSnapshot($snapshot);

        return new AssetReconciliationResult(
            asset: $asset,
            created: true,
            matched: false,
        );
    }

    public function createAssetFromSnapshot(AssetProviderSnapshot $snapshot): Asset
    {
        $asset = $this->createAsset($snapshot);
        $this->upsertExternalIdentifiers($snapshot, $asset);
        $this->attributeMerger->merge($asset, $snapshot);

        return $asset;
    }

    private function findHardMatchedAsset(AssetProviderSnapshot $snapshot): ?Asset
    {
        foreach ($snapshot->hardMatchIdentifiers() as $identifier) {
            $externalId = AssetExternalIdentifier::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $snapshot->tenantId)
                ->where('provider', $snapshot->provider)
                ->where('identifier_type', $identifier['type'])
                ->where('external_id', $identifier['value'])
                ->first();

            if ($externalId !== null) {
                return Asset::query()
                    ->withoutGlobalScopes()
                    ->where('tenant_id', $snapshot->tenantId)
                    ->where('organization_id', $snapshot->organizationId)
                    ->whereKey($externalId->asset_id)
                    ->first();
            }
        }

        $entraDeviceId = $snapshot->identifierValue(AssetExternalIdentifierType::EntraAzureAdDeviceId);
        if ($entraDeviceId !== null) {
            $crossProvider = AssetExternalIdentifier::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $snapshot->tenantId)
                ->where('identifier_type', AssetExternalIdentifierType::EntraAzureAdDeviceId)
                ->where('external_id', $entraDeviceId)
                ->first();

            if ($crossProvider !== null) {
                return Asset::query()
                    ->withoutGlobalScopes()
                    ->where('tenant_id', $snapshot->tenantId)
                    ->where('organization_id', $snapshot->organizationId)
                    ->whereKey($crossProvider->asset_id)
                    ->first();
            }

            if ($snapshot->provider === IntegrationType::MICROSOFT_ENTRA) {
                return $this->findLegacyEntraAssetIdentifierMatch($snapshot, $entraDeviceId);
            }
        }

        $intuneDeviceId = $snapshot->identifierValue(AssetExternalIdentifierType::EntraIntuneManagedDeviceId);
        if ($intuneDeviceId !== null && $snapshot->provider === IntegrationType::MICROSOFT_ENTRA) {
            $legacyMatch = $this->findLegacyEntraAssetIdentifierMatch($snapshot, $intuneDeviceId);
            if ($legacyMatch !== null) {
                return $legacyMatch;
            }
        }

        return null;
    }

    /**
     * @return array{asset: ?Asset, ambiguous: bool, candidates: Collection<int, Asset>}
     */
    private function findSoftMatchedAsset(AssetProviderSnapshot $snapshot): array
    {
        $serial = AssetIdentityNormalizer::normalizeSerial(
            $snapshot->identifierValue(AssetExternalIdentifierType::SerialNumber)
                ?? ($snapshot->attributes['serial_number'] ?? null),
        );

        if ($serial === null) {
            return [
                'asset' => null,
                'ambiguous' => false,
                'candidates' => collect(),
            ];
        }

        $mac = AssetIdentityNormalizer::normalizeMac(
            $snapshot->identifierValue(AssetExternalIdentifierType::MacAddress),
        );
        $hostname = AssetIdentityNormalizer::normalizeHostname(
            $snapshot->identifierValue(AssetExternalIdentifierType::Hostname)
                ?? ($snapshot->attributes['primary_hostname'] ?? $snapshot->attributes['name'] ?? null),
        );

        if ($mac === null && $hostname === null) {
            return [
                'asset' => null,
                'ambiguous' => false,
                'candidates' => collect(),
            ];
        }

        $serialCandidates = Asset::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $snapshot->tenantId)
            ->where('organization_id', $snapshot->organizationId)
            ->whereRaw('LOWER(TRIM(serial_number)) = ?', [strtolower($serial)])
            ->get();

        if ($serialCandidates->isEmpty()) {
            return [
                'asset' => null,
                'ambiguous' => false,
                'candidates' => collect(),
            ];
        }

        if ($mac !== null) {
            $macMatches = $this->filterCandidatesByIdentifier($snapshot, $serialCandidates, AssetExternalIdentifierType::MacAddress, $mac);
            if ($macMatches->count() === 1) {
                return [
                    'asset' => $macMatches->first(),
                    'ambiguous' => false,
                    'candidates' => $macMatches,
                ];
            }
            if ($macMatches->count() > 1) {
                return [
                    'asset' => null,
                    'ambiguous' => true,
                    'candidates' => $macMatches,
                ];
            }
        }

        if ($hostname !== null) {
            $hostnameMatches = $this->filterCandidatesByHostname($snapshot, $serialCandidates, $hostname);
            if ($hostnameMatches->count() === 1) {
                return [
                    'asset' => $hostnameMatches->first(),
                    'ambiguous' => false,
                    'candidates' => $hostnameMatches,
                ];
            }
            if ($hostnameMatches->count() > 1) {
                return [
                    'asset' => null,
                    'ambiguous' => true,
                    'candidates' => $hostnameMatches,
                ];
            }
        }

        return [
            'asset' => null,
            'ambiguous' => false,
            'candidates' => collect(),
        ];
    }

    /**
     * @param  Collection<int, Asset>  $candidates
     */
    private function filterCandidatesByIdentifier(
        AssetProviderSnapshot $snapshot,
        Collection $candidates,
        AssetExternalIdentifierType $type,
        string $normalizedValue,
    ): Collection {
        return $candidates->filter(function (Asset $asset) use ($snapshot, $type, $normalizedValue): bool {
            $stored = AssetExternalIdentifier::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $snapshot->tenantId)
                ->where('asset_id', $asset->getKey())
                ->where('identifier_type', $type)
                ->get();

            foreach ($stored as $identifier) {
                $candidateValue = $type === AssetExternalIdentifierType::MacAddress
                    ? AssetIdentityNormalizer::normalizeMac($identifier->external_id)
                    : trim($identifier->external_id);

                if ($candidateValue !== null && strcasecmp($candidateValue, $normalizedValue) === 0) {
                    return true;
                }
            }

            if ($type === AssetExternalIdentifierType::MacAddress) {
                return false;
            }

            return false;
        })->values();
    }

    /**
     * @param  Collection<int, Asset>  $candidates
     */
    private function filterCandidatesByHostname(
        AssetProviderSnapshot $snapshot,
        Collection $candidates,
        string $normalizedHostname,
    ): Collection {
        return $candidates->filter(function (Asset $asset) use ($normalizedHostname): bool {
            $hostname = AssetIdentityNormalizer::normalizeHostname($asset->primary_hostname)
                ?? AssetIdentityNormalizer::normalizeHostname($asset->name);

            return $hostname !== null && $hostname === $normalizedHostname;
        })->values();
    }

    /**
     * @param  Collection<int, Asset>  $candidates
     */
    private function handleAmbiguity(AssetProviderSnapshot $snapshot, Collection $candidates): AssetReconciliationResult
    {
        $candidateIds = $candidates->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();

        Asset::query()
            ->withoutGlobalScopes()
            ->whereIn('id', $candidateIds)
            ->update(['identity_resolution_status' => AssetIdentityResolutionStatus::Ambiguous]);

        $ambiguity = AssetIdentityAmbiguity::query()->create([
            'tenant_id' => $snapshot->tenantId,
            'organization_id' => $snapshot->organizationId,
            'organization_integration_id' => $snapshot->organizationIntegrationId,
            'provider' => $snapshot->provider,
            'candidate_asset_ids' => $candidateIds,
            'snapshot_json' => [
                'identifiers' => array_map(
                    fn (array $identifier): array => [
                        'type' => $identifier['type']->value,
                        'value' => $identifier['value'],
                    ],
                    $snapshot->identifiers,
                ),
                'attributes' => $snapshot->attributes,
            ],
            'status' => AssetIdentityAmbiguityStatus::Open,
        ]);

        return new AssetReconciliationResult(
            asset: null,
            ambiguous: true,
            ambiguity: $ambiguity,
        );
    }

    public function applySnapshotToAsset(AssetProviderSnapshot $snapshot, Asset $asset): void
    {
        $this->upsertExternalIdentifiers($snapshot, $asset);
        $this->attributeMerger->merge($asset, $snapshot);
        $asset->identity_resolution_status = AssetIdentityResolutionStatus::Resolved;
        $asset->last_inventory_sync_at = now();
        $asset->save();
    }

    private function createAsset(AssetProviderSnapshot $snapshot): Asset
    {
        $assetTypeId = $snapshot->attributes['asset_type_id'] ?? $this->defaultComputerAssetTypeId($snapshot->tenantId);
        $name = trim((string) ($snapshot->attributes['name'] ?? 'Unknown device'));
        if ($name === '') {
            $name = 'Unknown device';
        }

        return Asset::query()->create([
            'tenant_id' => $snapshot->tenantId,
            'organization_id' => $snapshot->organizationId,
            'asset_type_id' => $assetTypeId,
            'name' => $name,
            'serial_number' => AssetIdentityNormalizer::normalizeSerial($snapshot->attributes['serial_number'] ?? null),
            'primary_hostname' => $snapshot->attributes['primary_hostname'] ?? null,
            'operating_system' => $snapshot->attributes['operating_system'] ?? null,
            'lifecycle_status' => $snapshot->attributes['lifecycle_status'] ?? 'in_use',
            'owner_user_id' => $snapshot->attributes['owner_user_id'] ?? null,
            'identity_resolution_status' => AssetIdentityResolutionStatus::Resolved,
            'last_inventory_sync_at' => now(),
        ]);
    }

    private function defaultComputerAssetTypeId(int $tenantId): int
    {
        return AssetType::query()
            ->withoutGlobalScopes()
            ->firstOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'name' => 'Computer',
                ],
                [],
            )
            ->getKey();
    }

    private function findLegacyEntraAssetIdentifierMatch(AssetProviderSnapshot $snapshot, string $identifier): ?Asset
    {
        if (! AssetIdentifierHelper::isGuid($identifier)) {
            return null;
        }

        return Asset::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $snapshot->tenantId)
            ->where('organization_id', $snapshot->organizationId)
            ->where('asset_identifier', $identifier)
            ->first();
    }

    /**
     * Stores the snapshot's identifiers on the asset. An identifier is unique per tenant, provider and type,
     * so when another asset already holds it:
     *  - a device ID (hard match) moves to this asset, because the provider says this is that device now
     *    (for example a device that moved to another customer organization);
     *  - a soft hint such as a serial number, MAC address or hostname stays where it is. Two provider records
     *    can share these (re-enrolled devices, cloned images, placeholder serials), and the sync must not fail.
     */
    private function upsertExternalIdentifiers(AssetProviderSnapshot $snapshot, Asset $asset): void
    {
        $now = now();

        foreach ($snapshot->identifiers as $identifier) {
            $value = trim((string) ($identifier['value'] ?? ''));
            if ($value === '') {
                continue;
            }

            $type = $identifier['type'];
            $attributes = [
                'organization_id' => $snapshot->organizationId,
                'organization_integration_id' => $snapshot->organizationIntegrationId,
                'is_primary' => $type->isHardMatchType(),
                'last_seen_at' => $now,
            ];

            $existing = AssetExternalIdentifier::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $snapshot->tenantId)
                ->where('provider', $snapshot->provider)
                ->where('identifier_type', $type)
                ->where('external_id', $value)
                ->first();

            if ($existing === null) {
                AssetExternalIdentifier::query()->create([
                    'tenant_id' => $snapshot->tenantId,
                    'asset_id' => $asset->getKey(),
                    'provider' => $snapshot->provider,
                    'identifier_type' => $type,
                    'external_id' => $value,
                    ...$attributes,
                ]);

                continue;
            }

            if ((int) $existing->asset_id === (int) $asset->getKey()) {
                $existing->fill($attributes)->save();

                continue;
            }

            if ($type->isHardMatchType()) {
                Log::info('Asset external identifier moved to another asset.', [
                    'tenant_id' => $snapshot->tenantId,
                    'provider' => $snapshot->provider,
                    'identifier_type' => $type->value,
                    'from_asset_id' => $existing->asset_id,
                    'to_asset_id' => $asset->getKey(),
                ]);

                $existing->fill(['asset_id' => $asset->getKey(), ...$attributes])->save();

                continue;
            }

            Log::info('Asset external identifier already belongs to another asset; kept on the original asset.', [
                'tenant_id' => $snapshot->tenantId,
                'provider' => $snapshot->provider,
                'identifier_type' => $type->value,
                'existing_asset_id' => $existing->asset_id,
                'asset_id' => $asset->getKey(),
            ]);
        }
    }
}
