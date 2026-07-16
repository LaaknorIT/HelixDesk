<?php

namespace App\Filament\Agent\Resources\MailAccounts\Pages;

use App\Filament\Agent\Resources\MailAccounts\MailAccountResource;
use App\Models\MailAccount;
use App\Services\Mail\MailAccountConnectionVerifier;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
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

    protected function afterCreate(): void
    {
        /** @var MailAccount $record */
        $record = $this->getRecord();

        $result = app(MailAccountConnectionVerifier::class)->verify($record);

        $notification = Notification::make()
            ->title($result->ok ? 'Mail account created — connection OK' : 'Mail account created — connection failed')
            ->body($result->message)
            ->persistent();

        if ($result->ok) {
            $notification->success();
        } else {
            $notification->warning();
        }

        $notification->send();
    }
}
