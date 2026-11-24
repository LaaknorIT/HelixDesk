<?php

namespace App\Support;

use App\Data\AssetProviderSnapshot;
use App\Enums\AssetExternalIdentifierType;
use App\Models\Asset;
use App\Models\AssetExternalIdentifier;
use Illuminate\Support\Collection;

/**
 * Decides whether two device records can be the same physical device. Used when a device is linked by hostname alone
 * and when duplicate assets are merged: a name is a weak hint, so anything that proves two different devices wins.
 *
 * Two records are different devices when
 *  - both have a serial number and the serial numbers differ, or
 *  - both hold a device ID of the same provider and kind with different values (for example two Intune managed
 *    device IDs, or two Active Directory computer objects).
 */
final class AssetIdentityConflicts
{
    public static function snapshotConflictsWithAsset(AssetProviderSnapshot $snapshot, Asset $asset): bool
    {
        $serial = AssetIdentityNormalizer::normalizeSerial(
            $snapshot->identifierValue(AssetExternalIdentifierType::SerialNumber)
                ?? ($snapshot->attributes['serial_number'] ?? null),
        );

        if (self::serialsConflict($serial, $asset->serial_number)) {
            return true;
        }

        $held = self::hardIdentifierKeys(self::identifiersOf($asset));

        foreach ($snapshot->hardMatchIdentifiers() as $identifier) {
            $key = $snapshot->provider.'|'.$identifier['type']->value;
            if (isset($held[$key]) && ! in_array(mb_strtolower(trim($identifier['value'])), $held[$key], true)) {
                return true;
            }
        }

        return false;
    }

    public static function assetsConflict(Asset $first, Asset $second): bool
    {
        if (self::serialsConflict($first->serial_number, $second->serial_number)) {
            return true;
        }

        $firstKeys = self::hardIdentifierKeys(self::identifiersOf($first));
        $secondKeys = self::hardIdentifierKeys(self::identifiersOf($second));

        foreach ($firstKeys as $key => $values) {
            if (isset($secondKeys[$key]) && array_intersect($values, $secondKeys[$key]) === []) {
                return true;
            }
        }

        return false;
    }

    private static function serialsConflict(?string $first, ?string $second): bool
    {
        $first = AssetIdentityNormalizer::normalizeSerial($first);
        $second = AssetIdentityNormalizer::normalizeSerial($second);

        return $first !== null && $second !== null && strcasecmp($first, $second) !== 0;
    }

    /**
     * @return Collection<int, AssetExternalIdentifier>
     */
    private static function identifiersOf(Asset $asset): Collection
    {
        return AssetExternalIdentifier::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $asset->tenant_id)
            ->where('asset_id', $asset->getKey())
            ->get();
    }

    /**
     * @param  Collection<int, AssetExternalIdentifier>  $identifiers
     * @return array<string, list<string>> "provider|type" => lowercased values
     */
    private static function hardIdentifierKeys(Collection $identifiers): array
    {
        $keys = [];

        foreach ($identifiers as $identifier) {
            if (! $identifier->identifier_type->isHardMatchType()) {
                continue;
            }

            $keys[$identifier->provider.'|'.$identifier->identifier_type->value][] = mb_strtolower(trim($identifier->external_id));
        }

        return $keys;
    }
}
