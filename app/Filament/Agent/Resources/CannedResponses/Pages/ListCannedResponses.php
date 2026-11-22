<?php

namespace App\Filament\Agent\Resources\CannedResponses\Pages;

use App\Filament\Agent\Resources\CannedResponses\CannedResponseResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCannedResponses extends ListRecords
{
    protected static string $resource = CannedResponseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
