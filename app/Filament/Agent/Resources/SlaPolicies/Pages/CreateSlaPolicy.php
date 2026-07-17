<?php

namespace App\Filament\Agent\Resources\SlaPolicies\Pages;

use App\Filament\Agent\Resources\SlaPolicies\SlaPolicyResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateSlaPolicy extends CreateRecord
{
    protected static string $resource = SlaPolicyResource::class;

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
