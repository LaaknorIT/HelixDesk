<?php

namespace App\Services;

use App\Data\AssetProviderSnapshot;
use App\Models\Asset;
use App\Models\AssetAttributeProvenance;

final class AssetAttributeMerger
{
    /**
     * @var list<string>
     */
    private const MERGEABLE_ATTRIBUTES = [
        'name',
        'serial_number',
        'primary_hostname',
        'operating_system',
        'owner_user_id',
        'lifecycle_status',
        'asset_type_id',
    ];

    public function merge(Asset $asset, AssetProviderSnapshot $snapshot): Asset
    {
        $matrix = config('helixdesk_integrations.source_of_truth', []);
        $changed = false;

        foreach (self::MERGEABLE_ATTRIBUTES as $attribute) {
            if (! array_key_exists($attribute, $snapshot->attributes)) {
                continue;
            }

            $incomingValue = $snapshot->attributes[$attribute];
            if (! $this->shouldApply($asset, $attribute, $snapshot->provider, $matrix)) {
                continue;
            }

            $currentValue = $asset->{$attribute};
            if ($currentValue == $incomingValue) {
                continue;
            }

            $asset->{$attribute} = $incomingValue;
            $changed = true;

            AssetAttributeProvenance::query()->updateOrCreate(
                [
                    'tenant_id' => $snapshot->tenantId,
                    'asset_id' => $asset->getKey(),
                    'attribute' => $attribute,
                ],
                [
                    'provider' => $snapshot->provider,
                    'organization_integration_id' => $snapshot->organizationIntegrationId,
                    'synced_at' => now(),
                ],
            );
        }

        if ($changed) {
            $asset->last_inventory_sync_at = now();
            $asset->save();
        }

        return $asset;
    }

    public function providerPriority(string $attribute, string $provider): ?int
    {
        $providers = config('helixdesk_integrations.source_of_truth.'.$attribute, []);
        if (! is_array($providers)) {
            return null;
        }

        $index = array_search($provider, $providers, true);
        if ($index === false) {
            return null;
        }

        return (int) $index;
    }

    /**
     * @param  array<string, list<string>>  $matrix
     */
    private function shouldApply(Asset $asset, string $attribute, string $provider, array $matrix): bool
    {
        $incomingPriority = $this->providerPriority($attribute, $provider);
        if ($incomingPriority === null) {
            return false;
        }

        $provenance = AssetAttributeProvenance::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $asset->tenant_id)
            ->where('asset_id', $asset->getKey())
            ->where('attribute', $attribute)
            ->first();

        if ($provenance === null) {
            if ($attribute === 'asset_identifier') {
                return false;
            }

            return true;
        }

        $currentPriority = $this->providerPriority($attribute, $provenance->provider);
        if ($currentPriority === null) {
            return true;
        }

        return $incomingPriority <= $currentPriority;
    }
}
