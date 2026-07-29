<?php

namespace App\Enums;

enum SoftwareLicenseType: string
{
    case Subscription = 'subscription';
    case Perpetual = 'perpetual';
    case Oem = 'oem';
    case Trial = 'trial';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Subscription => __('Subscription'),
            self::Perpetual => __('Perpetual'),
            self::Oem => __('OEM'),
            self::Trial => __('Trial'),
            self::Other => __('Other'),
        };
    }
}
