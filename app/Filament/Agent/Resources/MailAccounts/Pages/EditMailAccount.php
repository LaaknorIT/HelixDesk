<?php

namespace App\Filament\Agent\Resources\MailAccounts\Pages;

use App\Filament\Agent\Resources\MailAccounts\Actions\MailAccountOperationalActions;
use App\Filament\Agent\Resources\MailAccounts\MailAccountResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditMailAccount extends EditRecord
{
    protected static string $resource = MailAccountResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $existing = is_array($this->record->connection_settings) ? $this->record->connection_settings : [];
        $incoming = is_array($data['connection_settings'] ?? null) ? $data['connection_settings'] : [];

        if (! filled($incoming['password'] ?? null)) {
            $incoming['password'] = $existing['password'] ?? null;
        }

        $data['connection_settings'] = array_merge($existing, $incoming);

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            MailAccountOperationalActions::testConnection(),
            MailAccountOperationalActions::checkEmail(),
            DeleteAction::make()
                ->visible(fn (): bool => MailAccountResource::canDelete($this->getRecord())),
        ];
    }
}
