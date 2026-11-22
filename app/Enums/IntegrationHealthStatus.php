<?php

namespace App\Enums;

use Filament\Support\Icons\Heroicon;

enum IntegrationHealthStatus: string
{
    case Healthy = 'healthy';
    case Warning = 'warning';
    case Error = 'error';
    case Disabled = 'disabled';

    public function label(): string
    {
        return match ($this) {
            self::Healthy => __('Healthy'),
            self::Warning => __('Needs attention'),
            self::Error => __('Failing'),
            self::Disabled => __('Disabled'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Healthy => 'success',
            self::Warning => 'warning',
            self::Error => 'danger',
            self::Disabled => 'gray',
        };
    }

    public function icon(): Heroicon
    {
        return match ($this) {
            self::Healthy => Heroicon::OutlinedCheckCircle,
            self::Warning => Heroicon::OutlinedExclamationTriangle,
            self::Error => Heroicon::OutlinedXCircle,
            self::Disabled => Heroicon::OutlinedPauseCircle,
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
