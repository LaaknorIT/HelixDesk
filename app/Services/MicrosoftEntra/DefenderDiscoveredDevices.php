<?php

namespace App\Services\MicrosoftEntra;

use App\Models\Asset;
use App\Models\AssetSourceRecord;
use App\Support\AssetIdentityNormalizer;
use App\Support\DeviceSourceKey;
use Illuminate\Support\Collection;

/**
 * Decides which devices Defender has only discovered on the network (not onboarded) are worth keeping.
 *
 * Such a device has no Entra device ID and often no name: Defender then reports its own GUID as the computer name.
 * A row for it says nothing about a device HelixDesk knows, so it is kept only when its name is a real hostname that
 * an asset or another device source in the tenant already has. Then the coverage report can show "Not onboarded"
 * on that device.
 */
final class DefenderDiscoveredDevices
{
    private const GUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    private const MACHINE_ID_PATTERN = '/^[0-9a-f]{40}$/';

    /**
     * The short hostname of a Defender computer name, or null when it is empty or only an ID.
     */
    public static function hostname(?string $computerDnsName): ?string
    {
        $hostname = AssetIdentityNormalizer::normalizeHostname($computerDnsName);
        if ($hostname === null) {
            return null;
        }

        if (preg_match(self::GUID_PATTERN, $hostname) === 1 || preg_match(self::MACHINE_ID_PATTERN, $hostname) === 1) {
            return null;
        }

        return $hostname;
    }

    /**
     * Short hostnames of devices the tenant already knows: assets (hostname or name) and devices reported by
     * sources other than Defender.
     *
     * @return array<string, true>
     */
    public static function knownHostnames(int $tenantId): array
    {
        $known = [];
        $add = function (?string $value) use (&$known): void {
            $hostname = AssetIdentityNormalizer::normalizeHostname($value);
            if ($hostname !== null) {
                $known[$hostname] = true;
            }
        };

        Asset::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->select(['id', 'name', 'primary_hostname'])
            ->chunkById(1000, function (Collection $assets) use ($add): void {
                foreach ($assets as $asset) {
                    $add($asset->primary_hostname);
                    $add($asset->name);
                }
            });

        AssetSourceRecord::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('source', '!=', DeviceSourceKey::MICROSOFT_DEFENDER)
            ->whereNull('gone_at')
            ->whereNotNull('hostname')
            ->distinct()
            ->pluck('hostname')
            ->each(fn (?string $hostname) => $add($hostname));

        return $known;
    }

    /**
     * @param  array<string, true>  $knownHostnames
     */
    public static function isKnown(?string $computerDnsName, array $knownHostnames): bool
    {
        $hostname = self::hostname($computerDnsName);

        return $hostname !== null && isset($knownHostnames[$hostname]);
    }
}
