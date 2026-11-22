<?php

namespace App\Enums;

enum DeviceClass: string
{
    case Workstation = 'workstation';
    case Server = 'server';
    case Mobile = 'mobile';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Workstation => __('Workstation'),
            self::Server => __('Server'),
            self::Mobile => __('Mobile'),
            self::Other => __('Other'),
        };
    }

    /**
     * Best guess from an operating system string as providers report it (e.g. "Windows Server 2022 Standard",
     * "Windows 11 Enterprise", "iOS", "macOS 14.5").
     */
    public static function fromOperatingSystem(?string $operatingSystem): self
    {
        $os = mb_strtolower(trim((string) $operatingSystem));

        if ($os === '') {
            return self::Other;
        }

        if (str_contains($os, 'server')) {
            return self::Server;
        }

        foreach (['ios', 'ipados', 'android'] as $mobile) {
            if (preg_match('/\b'.$mobile.'\b/', $os) === 1) {
                return self::Mobile;
            }
        }

        foreach (['windows', 'macos', 'mac os', 'os x', 'linux', 'ubuntu', 'chrome'] as $workstation) {
            if (str_contains($os, $workstation)) {
                return self::Workstation;
            }
        }

        return self::Other;
    }
}
