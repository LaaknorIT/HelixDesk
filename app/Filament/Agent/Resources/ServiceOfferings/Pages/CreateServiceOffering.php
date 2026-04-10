<?php

namespace App\Filament\Agent\Resources\ServiceOfferings\Pages;

use App\Filament\Agent\Resources\ServiceOfferings\ServiceOfferingResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateServiceOffering extends CreateRecord
{
    protected static string $resource = ServiceOfferingResource::class;

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
