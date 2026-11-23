<?php

namespace App\Data;

use App\Enums\DeviceClass;
use Illuminate\Support\Carbon;

/**
 * One device as a single device source sees it. Sync services attach this to the snapshot they already build;
 * the reconciliation service stores it in asset_source_records for the coverage report.
 */
final class DeviceSourceRecordData
{
    /**
     * @param  list<string>  $macAddresses
     * @param  array<string, mixed>  $metadata
     * @param  bool  $discoveredOnly  the source has seen the device (for example on the network) but does not manage
     *                                or protect it, such as a Defender device that can be onboarded but is not
     */
    public function __construct(
        public string $source,
        public string $providerRecordId,
        public ?string $displayName = null,
        public ?string $hostname = null,
        public ?string $fqdn = null,
        public ?string $serialNumber = null,
        public array $macAddresses = [],
        public ?string $operatingSystem = null,
        public ?DeviceClass $deviceClass = null,
        public ?bool $enabled = null,
        public ?Carbon $lastActivityAt = null,
        public array $metadata = [],
        public bool $discoveredOnly = false,
    ) {}

    /**
     * Parses a provider timestamp. Empty values and the "never" placeholder some APIs return (0001-01-01) become null.
     */
    public static function parseActivityTime(mixed $value): ?Carbon
    {
        if ($value === null || $value === '' || $value === 0) {
            return null;
        }

        try {
            $time = is_numeric($value)
                ? Carbon::createFromTimestamp((int) $value)
                : Carbon::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }

        return $time->year < 1971 ? null : $time;
    }
}
