<?php

namespace App\Filament\Agent\Resources\WorkItemCategories\Pages;

use App\Filament\Agent\Resources\WorkItemCategories\WorkItemCategoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListWorkItemCategories extends ListRecords
{
    protected static string $resource = WorkItemCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
