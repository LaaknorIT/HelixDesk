<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * One way to show dates and times across the interface, in the user's language.
 */
class DisplayFormats
{
    public const DATE_TIME = 'j M Y H:i';

    public const DATE = 'j M Y';

    public static function dateTime(?CarbonInterface $value): string
    {
        return $value?->translatedFormat(self::DATE_TIME) ?? '—';
    }
}
