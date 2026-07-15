<?php

namespace App\Services;

use App\Data\AssetProviderSnapshot;
use App\Enums\AssetExternalIdentifierType;
use App\Enums\AssetIdentityAmbiguityStatus;
use App\Enums\AssetIdentityResolutionStatus;
use App\Models\Asset;
use App\Models\AssetIdentityAmbiguity;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class AssetIdentityAmbiguityResolutionService
{
    public function __construct(
        private readonly AssetReconciliationService $assetReconciliationService,
    ) {}

    public function linkToAsset(AssetIdentityAmbiguity $ambiguity, Asset $asset): Asset
    {
        $this->assertResolvable($ambiguity);
        $this->assertAssetInScope($ambiguity, $asset);

        return DB::transaction(function () use ($ambiguity, $asset): Asset {
            $snapshot = $this->snapshotFromAmbiguity($ambiguity);
            $this->assetReconciliationService->applySnapshotToAsset($snapshot, $asset);

            $asset->identity_resolution_status = AssetIdentityResolutionStatus::Resolved;
            $asset->last_inventory_sync_at = now();
            $asset->save();

            $this->markResolved($ambiguity, $asset);
            $this->refreshCandidateResolutionStatuses($ambiguity);

            return $asset->fresh();
        });
    }

    public function createNewAsset(AssetIdentityAmbiguity $ambiguity): Asset
    {
        $this->assertResolvable($ambiguity);

        return DB::transaction(function () use ($ambiguity): Asset {
            $snapshot = $this->snapshotFromAmbiguity($ambiguity);
            $asset = $this->assetReconciliationService->createAssetFromSnapshot($snapshot);

            $this->markResolved($ambiguity, $asset);
            $this->refreshCandidateResolutionStatuses($ambiguity);

            return $asset->fresh();
        });
    }

    public function dismiss(AssetIdentityAmbiguity $ambiguity): void
    {
        $this->assertResolvable($ambiguity);

        DB::transaction(function () use ($ambiguity): void {
            $ambiguity->status = AssetIdentityAmbiguityStatus::Dismissed;
            $ambiguity->resolved_asset_id = null;
            $ambiguity->save();

            $this->refreshCandidateResolutionStatuses($ambiguity);
        });
    }

    private function assertResolvable(AssetIdentityAmbiguity $ambiguity): void
    {
        if ($ambiguity->status !== AssetIdentityAmbiguityStatus::Open) {
            throw new InvalidArgumentException('Ambiguity is not open for resolution.');
        }
    }

    private function assertAssetInScope(AssetIdentityAmbiguity $ambiguity, Asset $asset): void
    {
        if ((int) $asset->tenant_id !== (int) $ambiguity->tenant_id) {
            throw new InvalidArgumentException('Asset does not belong to the ambiguity tenant.');
        }

        $candidateIds = array_map('intval', $ambiguity->candidate_asset_ids ?? []);
        if (! in_array((int) $asset->getKey(), $candidateIds, true)) {
            throw new InvalidArgumentException('Asset is not a candidate for this ambiguity.');
        }
    }

    private function snapshotFromAmbiguity(AssetIdentityAmbiguity $ambiguity): AssetProviderSnapshot
    {
        $snapshotJson = is_array($ambiguity->snapshot_json) ? $ambiguity->snapshot_json : [];
        $rawIdentifiers = is_array($snapshotJson['identifiers'] ?? null) ? $snapshotJson['identifiers'] : [];
        $attributes = is_array($snapshotJson['attributes'] ?? null) ? $snapshotJson['attributes'] : [];

        $identifiers = [];
        foreach ($rawIdentifiers as $identifier) {
            if (! is_array($identifier)) {
                continue;
            }

            $typeValue = trim((string) ($identifier['type'] ?? ''));
            $value = trim((string) ($identifier['value'] ?? ''));
            if ($typeValue === '' || $value === '') {
                continue;
            }

            $identifiers[] = [
                'type' => AssetExternalIdentifierType::from($typeValue),
                'value' => $value,
            ];
        }

        return new AssetProviderSnapshot(
            tenantId: (int) $ambiguity->tenant_id,
            organizationId: (int) $ambiguity->organization_id,
            organizationIntegrationId: (string) $ambiguity->organization_integration_id,
            provider: (string) $ambiguity->provider,
            identifiers: $identifiers,
            attributes: $attributes,
        );
    }

    private function markResolved(AssetIdentityAmbiguity $ambiguity, Asset $asset): void
    {
        $ambiguity->status = AssetIdentityAmbiguityStatus::Resolved;
        $ambiguity->resolved_asset_id = $asset->getKey();
        $ambiguity->save();
    }

    private function refreshCandidateResolutionStatuses(AssetIdentityAmbiguity $ambiguity): void
    {
        $candidateIds = array_map('intval', $ambiguity->candidate_asset_ids ?? []);
        if ($candidateIds === []) {
            return;
        }

        foreach ($candidateIds as $candidateId) {
            $asset = Asset::query()->withoutGlobalScopes()->find($candidateId);
            if ($asset === null) {
                continue;
            }

            $hasOpenAmbiguity = AssetIdentityAmbiguity::query()
                ->where('tenant_id', $ambiguity->tenant_id)
                ->where('status', AssetIdentityAmbiguityStatus::Open)
                ->whereJsonContains('candidate_asset_ids', $candidateId)
                ->exists();

            if ($hasOpenAmbiguity) {
                $asset->identity_resolution_status = AssetIdentityResolutionStatus::Ambiguous;
            } elseif ($asset->identity_resolution_status === AssetIdentityResolutionStatus::Ambiguous) {
                $asset->identity_resolution_status = AssetIdentityResolutionStatus::Resolved;
            }

            $asset->save();
        }
    }
}
