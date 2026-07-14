<?php

namespace App\Filament\Agent\Resources\Queues\Pages;

use App\Filament\Agent\Resources\Queues\QueueResource;
use Filament\Resources\Pages\EditRecord;

class EditQueue extends EditRecord
{
    protected static string $resource = QueueResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['mail_account_ids'] = $this->record->mailAccounts()->pluck('mail_accounts.id')->all();

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['mail_account_ids']);

        return $data;
    }

    protected function afterSave(): void
    {
        $ids = $this->form->getState()['mail_account_ids'] ?? [];
        $this->record->mailAccounts()->sync(is_array($ids) ? $ids : []);
    }
}
