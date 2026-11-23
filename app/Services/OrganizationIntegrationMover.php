<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\AssetExternalIdentifier;
use App\Models\AssetIdentityAmbiguity;
use App\Models\AssetSourceRecord;
use App\Models\Organization;
use App\Models\OrganizationIntegration;
use App\Support\OrganizationLineage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Moves an integration to another organization (or to the root level), and moves the devices it is responsible for
 * with it:
 *  - a device above the new organization moves down to it, as it would on the next sync;
 *  - a device in the old organization that no other integration places there follows the integration, so an
 *    integration that was set up on the wrong customer can be corrected in one step;
 *  - devices never move up: moving an integration to a higher level leaves its devices where they are, because they
 *    are still within its reach and their organization is still the most specific one known.
 */
final class OrganizationIntegrationMover
{
    public function __construct(private readonly OrganizationLineage $lineage) {}

    /**
     * @return array{moved_devices: int}
     */
    public function move(OrganizationIntegration $integration, ?Organization $target): array
    {
        $tenantId = (int) $integration->tenant_id;
        $from = $integration->organization_id !== null ? (int) $integration->organization_id : null;
        $to = $target !== null ? (int) $target->getKey() : null;

        if ($target !== null && (int) $target->tenant_id !== $tenantId) {
            throw ValidationException::withMessages([
                'organization_id' => __('The integration can only move to an organization in the same tenant.'),
            ]);
        }

        if ($from === $to) {
            return ['moved_devices' => 0];
        }

        $duplicate = OrganizationIntegration::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->when($to === null, fn ($query) => $query->whereNull('organization_id'), fn ($query) => $query->where('organization_id', $to))
            ->where('type', $integration->type)
            ->where('scope_key', (string) $integration->scope_key)
            ->whereKeyNot($integration->getKey())
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'organization_id' => __('That organization already has the same integration for the same scope.'),
            ]);
        }

        $movedDevices = DB::transaction(function () use ($integration, $tenantId, $from, $to): int {
            $integration->organization_id = $to;
            $integration->save();

            AssetExternalIdentifier::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('organization_integration_id', $integration->getKey())
                ->update(['organization_id' => $to, 'updated_at' => now()]);

            AssetSourceRecord::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('organization_integration_id', $integration->getKey())
                ->whereNull('asset_id')
                ->update(['organization_id' => $to, 'updated_at' => now()]);

            AssetIdentityAmbiguity::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('organization_integration_id', $integration->getKey())
                ->update(['organization_id' => $to, 'updated_at' => now()]);

            return $this->moveDevices($integration, $tenantId, $from, $to);
        });

        Log::info('Integration moved to another organization.', [
            'tenant_id' => $tenantId,
            'organization_integration_id' => $integration->getKey(),
            'from_organization_id' => $from,
            'to_organization_id' => $to,
            'moved_devices' => $movedDevices,
        ]);

        foreach ([$from, $to] as $organizationId) {
            Cache::forget('device-coverage:missing:'.$tenantId.':'.$organizationId);
        }

        return ['moved_devices' => $movedDevices];
    }

    private function moveDevices(OrganizationIntegration $integration, int $tenantId, ?int $from, ?int $to): int
    {
        $assetIds = AssetSourceRecord::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('organization_integration_id', $integration->getKey())
            ->whereNotNull('asset_id')
            ->pluck('asset_id')
            ->merge(AssetExternalIdentifier::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('organization_integration_id', $integration->getKey())
                ->pluck('asset_id'))
            ->unique()
            ->values();

        $moved = 0;

        Asset::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereKey($assetIds->all())
            ->each(function (Asset $asset) use ($integration, $tenantId, $from, $to, &$moved): void {
                $current = $asset->organization_id !== null ? (int) $asset->organization_id : null;

                $moveDown = $this->lineage->isStrictAncestor($tenantId, $current, $to);
                $follow = $current === $from
                    && ! $this->lineage->isStrictAncestor($tenantId, $to, $current)
                    && ! $this->placedByAnotherIntegration($asset, $integration, $tenantId, $current);

                if (! $moveDown && ! $follow) {
                    return;
                }

                $asset->organization_id = $to;
                $asset->save();

                AssetSourceRecord::query()
                    ->withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('asset_id', $asset->getKey())
                    ->update(['organization_id' => $to, 'updated_at' => now()]);

                $moved++;
            });

        return $moved;
    }

    /**
     * True when another integration that reports the device sits in the device's organization or below it, so the
     * device's current placement comes from that integration and must not follow this one.
     */
    private function placedByAnotherIntegration(Asset $asset, OrganizationIntegration $integration, int $tenantId, ?int $current): bool
    {
        $otherIntegrationIds = AssetSourceRecord::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('asset_id', $asset->getKey())
            ->whereNull('gone_at')
            ->where('organization_integration_id', '!=', $integration->getKey())
            ->pluck('organization_integration_id')
            ->merge(AssetExternalIdentifier::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('asset_id', $asset->getKey())
                ->whereNotNull('organization_integration_id')
                ->where('organization_integration_id', '!=', $integration->getKey())
                ->pluck('organization_integration_id'))
            ->unique()
            ->values();

        if ($otherIntegrationIds->isEmpty()) {
            return false;
        }

        return OrganizationIntegration::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereKey($otherIntegrationIds->all())
            ->get(['id', 'organization_id'])
            ->contains(fn (OrganizationIntegration $other): bool => $this->lineage->isSameOrBelow(
                $tenantId,
                $other->organization_id !== null ? (int) $other->organization_id : null,
                $current,
            ));
    }
}
