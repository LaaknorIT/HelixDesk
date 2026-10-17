<?php

namespace App\Filament\Agent\Resources\People\Schemas;

use App\Models\Organization;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class PeopleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('organization_id')
                    ->label('Organization scope')
                    ->options(fn (): array => Organization::selectArray())
                    ->searchable()
                    ->default(null),
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('email')
                    ->email()
                    ->required()
                    ->maxLength(255),
                TextInput::make('password')
                    ->password()
                    ->revealable()
                    ->maxLength(255)
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->hidden(fn (?User $record): bool => $record?->isManagedByIdentityProvider() ?? false),
                Select::make('type')
                    ->options([
                        'agent' => 'Agent',
                        'end_user' => 'End user',
                    ])
                    ->required()
                    ->native(false),
            ]);
    }
}
