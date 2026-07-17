<?php

namespace App\Filament\Agent\Resources\WorkItemCategories\Pages;

use App\Filament\Agent\Resources\WorkItemCategories\WorkItemCategoryResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateWorkItemCategory extends CreateRecord
{
    protected static string $resource = WorkItemCategoryResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['tenant_id'] = Filament::getTenant()->getKey();

        return $data;
    }
}
