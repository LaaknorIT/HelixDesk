<?php

namespace App\Filament\Agent\Resources\Queues\Pages;

use App\Filament\Agent\Resources\Queues\QueueResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateQueue extends CreateRecord
{
    protected static string $resource = QueueResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $tenant = Filament::getTenant();
        $data['tenant_id'] = $tenant->getKey();

        return $data;
    }
}
