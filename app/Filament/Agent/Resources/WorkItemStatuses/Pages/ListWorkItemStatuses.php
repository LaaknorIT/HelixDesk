<?php

namespace App\Filament\Agent\Resources\WorkItemStatuses\Pages;

use App\Filament\Agent\Resources\WorkItemStatuses\WorkItemStatusResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListWorkItemStatuses extends ListRecords
{
    protected static string $resource = WorkItemStatusResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
