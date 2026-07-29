<?php

namespace App\Filament\Agent\Resources\SoftwareProducts\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class SoftwareProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('vendor_name')
                    ->label('Vendor')
                    ->maxLength(255),
                Select::make('supplier_id')
                    ->label('Supplier')
                    ->relationship('supplier', 'name')
                    ->searchable()
                    ->preload()
                    ->nullable(),
                TextInput::make('category')
                    ->maxLength(255),
                TextInput::make('external_reference')
                    ->label('External reference')
                    ->maxLength(255),
                Toggle::make('is_active')
                    ->label('Active')
                    ->default(true),
                Textarea::make('description')
                    ->columnSpanFull(),
                Textarea::make('partner_notes')
                    ->label('Partner notes')
                    ->helperText('Short MSP-facing partner context. Prefer Documents for full partner documentation.')
                    ->columnSpanFull(),
            ]);
    }
}
