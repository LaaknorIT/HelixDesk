<?php

namespace App\Filament\Agent\Resources\Departments\Pages;

use App\Filament\Agent\Resources\Departments\DepartmentResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateDepartment extends CreateRecord
{
    protected static string $resource = DepartmentResource::class;

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
