<?php

namespace App\Filament\Agent\Resources\SlaPolicies\Pages;

use App\Filament\Agent\Resources\SlaPolicies\SlaPolicyResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSlaPolicies extends ListRecords
{
    protected static string $resource = SlaPolicyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
