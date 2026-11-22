<?php

namespace App\Filament\Agent\Resources\CannedResponses\Pages;

use App\Filament\Agent\Resources\CannedResponses\CannedResponseResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateCannedResponse extends CreateRecord
{
    protected static string $resource = CannedResponseResource::class;

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
