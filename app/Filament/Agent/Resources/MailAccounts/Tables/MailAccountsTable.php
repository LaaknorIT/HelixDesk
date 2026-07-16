<?php

namespace App\Filament\Agent\Resources\MailAccounts\Tables;

use App\Enums\MailInboundProtocol;
use App\Enums\MailOutboundProtocol;
use App\Filament\Agent\Resources\MailAccounts\Actions\MailAccountOperationalActions;
use App\Filament\Agent\Resources\MailAccounts\MailAccountResource;
use App\Models\MailAccount;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MailAccountsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email_address')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('protocol')
                    ->badge()
                    ->formatStateUsing(fn (MailInboundProtocol $state): string => match ($state) {
                        MailInboundProtocol::Imap => 'IMAP',
                        MailInboundProtocol::Pop3 => 'POP3',
                        MailInboundProtocol::MicrosoftGraph => 'Microsoft 365',
                        MailInboundProtocol::GoogleGmailApi => 'Google Workspace',
                    })
                    ->sortable(),
                TextColumn::make('outbound_protocol')
                    ->label('Outbound')
                    ->badge()
                    ->formatStateUsing(fn (MailOutboundProtocol $state): string => match ($state) {
                        MailOutboundProtocol::Smtp => 'SMTP',
                        MailOutboundProtocol::MicrosoftGraph => 'Microsoft 365',
                        MailOutboundProtocol::GoogleGmailApi => 'Google Workspace',
                    }),
                IconColumn::make('is_enabled')
                    ->boolean(),
                TextColumn::make('last_polled_at')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('last_poll_error')
                    ->label('Last error')
                    ->limit(40)
                    ->tooltip(fn (MailAccount $record): ?string => $record->last_poll_error)
                    ->color('danger')
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->recordActions([
                MailAccountOperationalActions::testConnection(),
                MailAccountOperationalActions::checkEmail(),
                EditAction::make()
                    ->visible(fn (MailAccount $record): bool => MailAccountResource::canEdit($record)),
                DeleteAction::make()
                    ->visible(fn (MailAccount $record): bool => MailAccountResource::canDelete($record)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn (): bool => MailAccountResource::canDeleteAny()),
                ]),
            ])
            ->defaultSort('name');
    }
}
