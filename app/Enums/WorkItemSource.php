<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum WorkItemSource: string implements HasLabel
{
    case Portal = 'portal';
    case Email = 'email';
    case Api = 'api';
    case Monitoring = 'monitoring';

    public function getLabel(): string
    {
        return match ($this) {
            self::Portal => __('Portal'),
            self::Email => __('Email'),
            self::Api => __('API'),
            self::Monitoring => __('Monitoring'),
        };
    }
}
