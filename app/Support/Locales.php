<?php

namespace App\Support;

use App\Models\User;

/**
 * The interface languages HelixDesk offers, and which one applies to a user.
 */
class Locales
{
    /**
     * @return array<string, string> Locale code => the language's own name.
     */
    public static function available(): array
    {
        /** @var array<string, string> $locales */
        $locales = config('helixdesk.locales', ['en' => 'English']);

        return $locales;
    }

    public static function isAvailable(?string $locale): bool
    {
        return $locale !== null && array_key_exists($locale, static::available());
    }

    public static function default(): string
    {
        $locale = (string) config('app.locale', 'en');

        return static::isAvailable($locale) ? $locale : 'en';
    }

    public static function forUser(?User $user): string
    {
        $locale = $user?->locale;

        return static::isAvailable($locale) ? (string) $locale : static::default();
    }
}
