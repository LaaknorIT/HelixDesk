<?php

namespace App\Filament\Concerns;

use Illuminate\Contracts\Support\Htmlable;

/**
 * Runs a custom page's English title and menu label through the translator.
 */
trait TranslatesPageLabels
{
    public function getTitle(): string|Htmlable
    {
        $title = parent::getTitle();

        return is_string($title) ? __($title) : $title;
    }

    public static function getNavigationLabel(): string
    {
        return __(parent::getNavigationLabel());
    }
}
