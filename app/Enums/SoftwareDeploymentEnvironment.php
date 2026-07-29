<?php

namespace App\Enums;

enum SoftwareDeploymentEnvironment: string
{
    case Production = 'production';
    case Test = 'test';
    case Dr = 'dr';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Production => __('Production'),
            self::Test => __('Test'),
            self::Dr => __('Disaster recovery'),
            self::Other => __('Other'),
        };
    }
}
