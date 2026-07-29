<?php

namespace App\Filament\Agent\Resources\SoftwareProducts\Pages;

use App\Filament\Agent\Resources\SoftwareProducts\SoftwareProductResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSoftwareProducts extends ListRecords
{
    protected static string $resource = SoftwareProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
