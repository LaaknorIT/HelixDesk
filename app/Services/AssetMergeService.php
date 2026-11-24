<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\AssetAttributeProvenance;
use App\Models\AssetExternalIdentifier;
use App\Models\AssetIdentityAmbiguity;
use App\Models\AssetSourceRecord;
use App\Support\AssetIdentityConflicts;
use App\Support\AssetIdentityNormalizer;
use App\Support\OrganizationLineage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Merges assets that are the same physical device, for example a computer that Active Directory created and that
 * Intune or the RMM created again because the two had no identifier in common. Everything linked to the duplicate
 * (device IDs, source records, work items, software, patches, relationships, documents) moves to the asset that is
 * kept, and the duplicate is deleted.
 */
final class AssetMergeService
{
    /**
     * Asset fields that a merge fills in or takes over, by the source-of-truth order in config.
     *
     * @var list<string>
     */
    private const MERGED_ATTRIBUTES = [
        'name',
        'serial_number',
        'primary_hostname',
        'operating_system',
        'owner_user_id',
        'asset_type_id',
    ];

    /**
     * Fields without a provider that are only filled in when the kept asset has none.
     *
     * @var list<string>
     */
    private const FILLED_ATTRIBUTES = [
        'asset_identifier',
        'purchase_date',
        'warranty_date',
        'last_inventory_sync_at',
    ];

    public function __construct(
        private readonly AssetAttributeMerger $attributeMerger,
        private readonly OrganizationLineage $lineage,
    ) {}

    /**
     * Why the two assets cannot be merged, or null when they can.
     */
    public function mergeBlocker(Asset $keep, Asset $duplicate): ?string
    {
        if ((int) $keep->getKey() === (int) $duplicate->getKey()) {
            return __('An asset cannot be merged into itself.');
        }

        if ((int) $keep->tenant_id !== (int) $duplicate->tenant_id) {
            return __('The assets belong to different tenants.');
        }

        $tenantId = (int) $keep->tenant_id;
        $keepOrganization = $keep->organization_id !== null ? (int) $keep->organization_id : null;
        $duplicateOrganization = $duplicate->organization_id !== null ? (int) $duplicate->organization_id : null;

        if (! $this->lineage->isSameOrBelow($tenantId, $keepOrganization, $duplicateOrganization)
            && ! $this->lineage->isSameOrBelow($tenantId, $duplicateOrganization, $keepOrganization)) {
            return __('The assets belong to different customers.');
        }

        if (AssetIdentityConflicts::assetsConflict($keep, $duplicate)) {
            return __('The assets have different serial numbers or device IDs from the same source, so they are different devices.');
        }

        return null;
    }

    /**
     * Moves everything from $duplicate to $keep and deletes $duplicate. The kept asset ends up in the more specific
     * of the two organizations.
     */
    public function merge(Asset $keep, Asset $duplicate): Asset
    {
        $blocker = $this->mergeBlocker($keep, $duplicate);
        if ($blocker !== null) {
            throw new InvalidArgumentException($blocker);
        }

        $tenantId = (int) $keep->tenant_id;
        $keepId = (int) $keep->getKey();
        $duplicateId = (int) $duplicate->getKey();

        DB::transaction(function () use ($keep, $duplicate, $tenantId, $keepId, $duplicateId): void {
            $this->mergeAttributes($keep, $duplicate);

            $keepOrganization = $keep->organization_id !== null ? (int) $keep->organization_id : null;
            $duplicateOrganization = $duplicate->organization_id !== null ? (int) $duplicate->organization_id : null;
            if ($this->lineage->depth($tenantId, $duplicateOrganization) > $this->lineage->depth($tenantId, $keepOrganization)) {
                $keep->organization_id = $duplicateOrganization;
            }

            if ($keep->isDirty()) {
                $keep->save();
            }

            // Identifiers are unique per tenant, provider, type and value, so they never collide.
            AssetExternalIdentifier::query()
                ->withoutGlobalScopes()
                ->where('asset_id', $duplicateId)
                ->update(['asset_id' => $keepId, 'updated_at' => now()]);

            AssetSourceRecord::query()
                ->withoutGlobalScopes()
                ->where('asset_id', $duplicateId)
                ->update(['asset_id' => $keepId, 'updated_at' => now()]);

            AssetSourceRecord::query()
                ->withoutGlobalScopes()
                ->where('asset_id', $keepId)
                ->update(['organization_id' => $keep->organization_id]);

            $this->moveRows('asset_software_installations', 'asset_id', $duplicateId, $keepId, ['tenant_id', 'provider', 'external_id']);
            $this->moveRows('asset_patch_status', 'asset_id', $duplicateId, $keepId, ['tenant_id', 'provider', 'organization_integration_id']);
            $this->moveRows('asset_patch_items', 'asset_id', $duplicateId, $keepId, ['tenant_id', 'provider', 'organization_integration_id', 'kb_id']);
            $this->moveRows('asset_work_item', 'asset_id', $duplicateId, $keepId, ['work_item_id']);
            $this->moveRows('monitoring_event_asset', 'asset_id', $duplicateId, $keepId, ['monitoring_event_id']);
            $this->moveRows('service_offering_asset', 'asset_id', $duplicateId, $keepId, ['service_offering_id', 'role']);
            $this->moveRows('software_deployment_asset', 'asset_id', $duplicateId, $keepId, ['software_deployment_id', 'role']);
            $this->moveDocumentLinks($duplicate, $keepId);
            $this->moveRelationships($duplicateId, $keepId);

            AssetIdentityAmbiguity::query()
                ->withoutGlobalScopes()
                ->where('resolved_asset_id', $duplicateId)
                ->update(['resolved_asset_id' => $keepId]);

            AssetAttributeProvenance::query()
                ->withoutGlobalScopes()
                ->where('asset_id', $duplicateId)
                ->delete();

            $duplicate->delete();
        });

        Log::info('Duplicate asset merged.', [
            'tenant_id' => $tenantId,
            'kept_asset_id' => $keepId,
            'merged_asset_id' => $duplicateId,
        ]);

        Cache::forget('device-coverage:missing:'.$tenantId.':'.$keep->organization_id);

        return $keep->refresh();
    }

    /**
     * Groups of synced assets that are the same device: the same short hostname in the same organization, no
     * conflicting serial number or device ID, and each found by at least one integration (assets created by hand
     * are left alone, since several can share a name such as "Printer"). The first asset of a group is the one to
     * keep: the one with a serial number, otherwise the oldest.
     *
     * @return list<list<Asset>>
     */
    public function findDuplicateGroups(int $tenantId, ?int $organizationId = null): array
    {
        $syncedAssetIds = AssetExternalIdentifier::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->distinct()
            ->pluck('asset_id');

        $assets = Asset::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $syncedAssetIds)
            ->when($organizationId !== null, fn ($query) => $query->whereIn('organization_id', [
                $organizationId,
                ...$this->lineage->descendantIds($tenantId, $organizationId),
            ]))
            ->orderBy('id')
            ->get();

        $byName = $assets->groupBy(fn (Asset $asset): string => ($asset->organization_id ?? 0).'|'.(
            AssetIdentityNormalizer::normalizeHostname($asset->primary_hostname)
                ?? AssetIdentityNormalizer::normalizeHostname($asset->name)
                ?? ''
        ));

        $groups = [];
        foreach ($byName as $key => $candidates) {
            if ($candidates->count() < 2 || str_ends_with((string) $key, '|')) {
                continue;
            }

            foreach ($this->clusters($candidates) as $cluster) {
                if (count($cluster) > 1) {
                    $groups[] = $cluster;
                }
            }
        }

        return $groups;
    }

    /**
     * Merges every group findDuplicateGroups() finds.
     *
     * @return list<array{kept: Asset, merged: list<int>}>
     */
    public function mergeDuplicates(int $tenantId, ?int $organizationId = null): array
    {
        $results = [];

        foreach ($this->findDuplicateGroups($tenantId, $organizationId) as $group) {
            $keep = array_shift($group);
            $merged = [];

            foreach ($group as $duplicate) {
                if ($this->mergeBlocker($keep, $duplicate) !== null) {
                    continue;
                }

                $merged[] = (int) $duplicate->getKey();
                $keep = $this->merge($keep, $duplicate);
            }

            if ($merged !== []) {
                $results[] = ['kept' => $keep, 'merged' => $merged];
            }
        }

        return $results;
    }

    /**
     * Splits assets with the same name into groups with no conflicts inside, the asset to keep first.
     *
     * @param  Collection<int, Asset>  $candidates
     * @return list<list<Asset>>
     */
    private function clusters(Collection $candidates): array
    {
        $ordered = $candidates
            ->sortBy(fn (Asset $asset): array => [
                AssetIdentityNormalizer::normalizeSerial($asset->serial_number) === null ? 1 : 0,
                (int) $asset->getKey(),
            ])
            ->values();

        $clusters = [];
        foreach ($ordered as $asset) {
            foreach ($clusters as $index => $cluster) {
                $fits = true;
                foreach ($cluster as $member) {
                    if (AssetIdentityConflicts::assetsConflict($member, $asset)) {
                        $fits = false;

                        break;
                    }
                }

                if ($fits) {
                    $clusters[$index][] = $asset;

                    continue 2;
                }
            }

            $clusters[] = [$asset];
        }

        return $clusters;
    }

    /**
     * Takes each field from the asset whose provider ranks higher for it, and fills empty fields from the duplicate.
     */
    private function mergeAttributes(Asset $keep, Asset $duplicate): void
    {
        $provenance = AssetAttributeProvenance::query()
            ->withoutGlobalScopes()
            ->whereIn('asset_id', [$keep->getKey(), $duplicate->getKey()])
            ->get()
            ->groupBy('asset_id');

        $keepProvenance = ($provenance[$keep->getKey()] ?? collect())->keyBy('attribute');
        $duplicateProvenance = ($provenance[$duplicate->getKey()] ?? collect())->keyBy('attribute');

        foreach (self::MERGED_ATTRIBUTES as $attribute) {
            $duplicateValue = $duplicate->{$attribute};
            if ($duplicateValue === null || $duplicateValue === '') {
                continue;
            }

            $keepValue = $keep->{$attribute};
            $takeDuplicate = $keepValue === null || $keepValue === '';

            if (! $takeDuplicate && isset($duplicateProvenance[$attribute])) {
                $duplicateRank = $this->attributeMerger->providerPriority($attribute, $duplicateProvenance[$attribute]->provider);
                $keepRank = isset($keepProvenance[$attribute])
                    ? $this->attributeMerger->providerPriority($attribute, $keepProvenance[$attribute]->provider)
                    : null;

                $takeDuplicate = $duplicateRank !== null && ($keepRank === null || $duplicateRank < $keepRank);
            }

            if (! $takeDuplicate) {
                continue;
            }

            $keep->{$attribute} = $duplicateValue;

            if (isset($duplicateProvenance[$attribute])) {
                AssetAttributeProvenance::query()->withoutGlobalScopes()->updateOrCreate(
                    ['tenant_id' => $keep->tenant_id, 'asset_id' => $keep->getKey(), 'attribute' => $attribute],
                    [
                        'provider' => $duplicateProvenance[$attribute]->provider,
                        'organization_integration_id' => $duplicateProvenance[$attribute]->organization_integration_id,
                        'synced_at' => $duplicateProvenance[$attribute]->synced_at,
                    ],
                );
            }
        }

        foreach (self::FILLED_ATTRIBUTES as $attribute) {
            if ($keep->{$attribute} === null && $duplicate->{$attribute} !== null) {
                $keep->{$attribute} = $duplicate->{$attribute};
            }
        }
    }

    /**
     * Moves rows from the duplicate to the kept asset. A row the kept asset already has (same values in
     * $uniqueColumns) is deleted instead, so unique keys hold.
     *
     * @param  list<string>  $uniqueColumns
     */
    private function moveRows(string $table, string $column, int $fromId, int $toId, array $uniqueColumns): void
    {
        foreach (DB::table($table)->where($column, $fromId)->get() as $row) {
            $row = (array) $row;
            $match = fn ($query) => $query->where(function ($inner) use ($row, $uniqueColumns): void {
                foreach ($uniqueColumns as $uniqueColumn) {
                    $row[$uniqueColumn] === null
                        ? $inner->whereNull($uniqueColumn)
                        : $inner->where($uniqueColumn, $row[$uniqueColumn]);
                }
            });

            $alreadyThere = DB::table($table)->where($column, $toId)->where($match)->exists();

            $query = DB::table($table)->where($column, $fromId)->where($match);
            $alreadyThere ? $query->delete() : $query->update([$column => $toId]);
        }
    }

    private function moveDocumentLinks(Asset $duplicate, int $toId): void
    {
        $morphClass = $duplicate->getMorphClass();

        foreach (DB::table('document_links')->where('linkable_type', $morphClass)->where('linkable_id', $duplicate->getKey())->get() as $link) {
            $alreadyThere = DB::table('document_links')
                ->where('document_id', $link->document_id)
                ->where('linkable_type', $morphClass)
                ->where('linkable_id', $toId)
                ->exists();

            $query = DB::table('document_links')->where('id', $link->id);
            $alreadyThere ? $query->delete() : $query->update(['linkable_id' => $toId]);
        }
    }

    private function moveRelationships(int $fromId, int $toId): void
    {
        DB::table('asset_relationships')->where('parent_asset_id', $fromId)->update(['parent_asset_id' => $toId]);
        DB::table('asset_relationships')->where('child_asset_id', $fromId)->update(['child_asset_id' => $toId]);

        // A relationship between the two merged assets would now point at itself.
        DB::table('asset_relationships')->where('parent_asset_id', $toId)->where('child_asset_id', $toId)->delete();

        $seen = [];
        foreach (DB::table('asset_relationships')->where(fn ($query) => $query->where('parent_asset_id', $toId)->orWhere('child_asset_id', $toId))->orderBy('id')->get() as $relationship) {
            $key = $relationship->parent_asset_id.'|'.$relationship->child_asset_id.'|'.$relationship->relationship_type;
            if (isset($seen[$key])) {
                DB::table('asset_relationships')->where('id', $relationship->id)->delete();

                continue;
            }

            $seen[$key] = true;
        }
    }
}
