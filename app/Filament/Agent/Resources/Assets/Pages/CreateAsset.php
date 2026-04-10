<?php

namespace App\Filament\Agent\Resources\Assets\Pages;

use App\Filament\Agent\Resources\Assets\AssetResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateAsset extends CreateRecord
{
    protected static string $resource = AssetResource::class;

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
