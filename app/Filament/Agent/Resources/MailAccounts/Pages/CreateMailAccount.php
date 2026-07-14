<?php

namespace App\Filament\Agent\Resources\MailAccounts\Pages;

use App\Filament\Agent\Resources\MailAccounts\MailAccountResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateMailAccount extends CreateRecord
{
    protected static string $resource = MailAccountResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $tenant = Filament::getTenant();
        $data['tenant_id'] = $tenant?->getKey();

        return $data;
    }
}
