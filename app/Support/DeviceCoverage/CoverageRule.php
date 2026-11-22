<?php

namespace App\Support\DeviceCoverage;

use App\Enums\DeviceClass;
use App\Enums\DeviceSourceRole;

/**
 * "Devices that are active in a source with one of $whenActiveIn roles are expected in a source with one of
 * $expectIn roles." Rules name roles, never products, so any integration that registers a source is checked.
 */
final readonly class CoverageRule
{
    /**
     * @param  list<DeviceSourceRole>  $whenActiveIn
     * @param  list<DeviceSourceRole>  $expectIn
     * @param  list<DeviceClass>  $deviceClasses
     * @param  list<string>  $operatingSystems  lowercase fragments; empty means any operating system
     */
    public function __construct(
        public string $key,
        public string $name,
        public string $likelyCause,
        public array $whenActiveIn,
        public array $expectIn,
        public array $deviceClasses,
        public array $operatingSystems = [],
    ) {}

    public function appliesToOperatingSystem(?string $operatingSystem): bool
    {
        if ($this->operatingSystems === []) {
            return true;
        }

        $os = mb_strtolower((string) $operatingSystem);

        foreach ($this->operatingSystems as $fragment) {
            if (str_contains($os, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
