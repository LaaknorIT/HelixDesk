<?php

namespace App\Filament\Agent\Resources\WorkItemStatuses\Pages;

use App\Filament\Agent\Resources\WorkItemStatuses\WorkItemStatusResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateWorkItemStatus extends CreateRecord
{
    protected static string $resource = WorkItemStatusResource::class;

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
