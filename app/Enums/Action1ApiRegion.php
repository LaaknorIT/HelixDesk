<?php

namespace App\Enums;

enum Action1ApiRegion: string
{
    case Us = 'us';
    case Eu = 'eu';

    public function apiBaseUrl(): string
    {
        return match ($this) {
            self::Us => config('helixdesk_integrations.action1.regions.us'),
            self::Eu => config('helixdesk_integrations.action1.regions.eu'),
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Us => __('United States'),
            self::Eu => __('European Union'),
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
