<?php

namespace App\Filament\Agent\Resources\SoftwareProducts\Pages;

use App\Filament\Agent\Resources\SoftwareProducts\SoftwareProductResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateSoftwareProduct extends CreateRecord
{
    protected static string $resource = SoftwareProductResource::class;

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
