<?php

namespace App\Filament\Agent\Resources\Queues\Schemas;

use App\Models\MailAccount;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class QueueForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Textarea::make('description')
                    ->default(null)
                    ->rows(3)
                    ->columnSpanFull(),
                Section::make('Email')
                    ->schema([
                        CheckboxList::make('mail_account_ids')
                            ->label('Mail accounts')
                            ->options(function (): array {
                                $tenant = Filament::getTenant();
                                if ($tenant === null) {
                                    return [];
                                }

                                return MailAccount::query()
                                    ->where('tenant_id', $tenant->getKey())
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                                    ->all();
                            })
                            ->columns(2)
                            ->live(),
                        Select::make('default_mail_account_id')
                            ->label('Default outbound address')
                            ->helperText('Used when agents reply on work_items not created from email (portal, API, etc.).')
                            ->options(fn (Get $get): array => MailAccount::query()
                                ->whereIn('id', $get('mail_account_ids') ?? [])
                                ->orderBy('name')
                                ->pluck('email_address', 'id')
                                ->all())
                            ->searchable()
                            ->nullable(),
                    ]),
            ]);
    }
}
