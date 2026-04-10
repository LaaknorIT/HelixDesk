<?php

namespace App\Filament\GlobalAdmin\Resources\Tenants\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class TenantForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Tenant name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('slug')
                    ->label('Slug')
                    ->required()
                    ->maxLength(255)
                    ->helperText('Used for routing (optional for now)'),
                Select::make('status')
                    ->required()
                    ->options([
                        'active' => 'Active',
                        'readonly' => 'Read only',
                        'disabled' => 'Disabled',
                    ]),
                TextInput::make('region')
                    ->required()
                    ->maxLength(50),
            ]);
    }
}
