<?php

namespace App\Enums;

enum SoftwareDeploymentStatus: string
{
    case Active = 'active';
    case Planned = 'planned';
    case Retired = 'retired';

    public function label(): string
    {
        return match ($this) {
            self::Active => __('Active'),
            self::Planned => __('Planned'),
            self::Retired => __('Retired'),
        };
    }
}
