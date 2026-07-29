<?php

namespace App\Enums;

enum SoftwareLicenseMetric: string
{
    case Users = 'users';
    case Devices = 'devices';
    case Cores = 'cores';
    case Unlimited = 'unlimited';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Users => __('Users'),
            self::Devices => __('Devices'),
            self::Cores => __('Cores'),
            self::Unlimited => __('Unlimited'),
            self::Other => __('Other'),
        };
    }
}
