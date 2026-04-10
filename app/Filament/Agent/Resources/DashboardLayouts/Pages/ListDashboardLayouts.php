<?php

namespace App\Filament\Agent\Resources\DashboardLayouts\Pages;

use App\Filament\Agent\Resources\DashboardLayouts\DashboardLayoutResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDashboardLayouts extends ListRecords
{
    protected static string $resource = DashboardLayoutResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
