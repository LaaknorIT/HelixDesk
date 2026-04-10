<?php

namespace App\Filament\Agent\Resources\Changes\Pages;

use App\Filament\Agent\Resources\Changes\ChangeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListChanges extends ListRecords
{
    protected static string $resource = ChangeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
