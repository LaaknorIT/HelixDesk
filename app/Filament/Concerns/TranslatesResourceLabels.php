<?php

namespace App\Filament\Concerns;

use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Str;

/**
 * Runs a resource's English labels through the translator, so menus, titles and breadcrumbs follow the
 * user's language. Labels stay in sentence case ("Create problem", not "Create Problem"); model labels are written in lowercase.
 */
trait TranslatesResourceLabels
{
    public static function getModelLabel(): string
    {
        return __(parent::getModelLabel());
    }

    public static function getPluralModelLabel(): string
    {
        if (filled(static::$pluralModelLabel)) {
            return __(static::$pluralModelLabel);
        }

        return __(Str::plural(parent::getModelLabel()));
    }

    /**
     * Used inside sentences ("Create problem"), so it keeps the label's own case.
     */
    public static function getTitleCaseModelLabel(): string
    {
        return static::getModelLabel();
    }

    /**
     * Used on its own (page titles, breadcrumbs), so only the first letter is capitalized.
     */
    public static function getTitleCasePluralModelLabel(): string
    {
        return Str::ucfirst(static::getPluralModelLabel());
    }

    public static function getNavigationLabel(): string|Htmlable
    {
        $label = parent::getNavigationLabel();

        return is_string($label) ? __($label) : $label;
    }
}
