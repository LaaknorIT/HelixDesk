<?php

namespace App\Filament\EndUser\Pages;

use App\Filament\EndUser\Actions\CreateTicketAction;
use Filament\Pages\Dashboard as BaseDashboard;

/**
 * The end user's start page: report an issue, and see the tickets that are still open.
 */
class Dashboard extends BaseDashboard
{
    public function getTitle(): string
    {
        return __('Welcome');
    }

    public function getSubheading(): ?string
    {
        return __('Report an issue or follow the tickets you have reported.');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateTicketAction::make(),
        ];
    }
}
