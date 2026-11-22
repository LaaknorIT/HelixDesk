<?php

namespace App\Enums;

use Filament\Support\Icons\Heroicon;

enum IntegrationHealthSeverity: string
{
    case Error = 'error';
    case Warning = 'warning';

    public function label(): string
    {
        return match ($this) {
            self::Error => __('Error'),
            self::Warning => __('Warning'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Error => 'danger',
            self::Warning => 'warning',
        };
    }

    public function icon(): Heroicon
    {
        return match ($this) {
            self::Error => Heroicon::OutlinedXCircle,
            self::Warning => Heroicon::OutlinedExclamationTriangle,
        };
    }
}
