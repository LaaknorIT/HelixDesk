<?php

namespace App\Filament\Agent\Resources\Queues\Pages;

use App\Filament\Agent\Resources\Queues\QueueResource;
use Filament\Resources\Pages\CreateRecord;

class CreateQueue extends CreateRecord
{
    protected static string $resource = QueueResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        unset($data['mail_account_ids']);

        return $data;
    }

    protected function afterCreate(): void
    {
        $ids = $this->form->getState()['mail_account_ids'] ?? [];
        if (is_array($ids) && $ids !== []) {
            $this->record->mailAccounts()->sync($ids);
        }
    }
}
