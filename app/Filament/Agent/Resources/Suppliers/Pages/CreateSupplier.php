<?php

namespace App\Filament\Agent\Resources\Suppliers\Pages;

use App\Filament\Agent\Resources\Suppliers\SupplierResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateSupplier extends CreateRecord
{
    protected static string $resource = SupplierResource::class;

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
