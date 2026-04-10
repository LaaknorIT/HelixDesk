<?php

namespace App\Filament\Agent\Resources\ServiceOfferings\Schemas;

use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class ServiceOfferingForm
{
    public static function configure(Schema $schema): Schema
    {
        $kindLabels = collect(config('helixdesk_service_offering.kinds', []))
            ->mapWithKeys(fn (array $def, string $key): array => [$key => $def['label'] ?? Str::headline($key)])
            ->all();

        return $schema
            ->components([
                Select::make('organization_id')
                    ->label('Organization')
                    ->relationship('organization', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Textarea::make('description')
                    ->default(null)
                    ->columnSpanFull(),
                TextInput::make('code')
                    ->label('Code')
                    ->maxLength(255)
                    ->default(null),
                Select::make('kind')
                    ->options($kindLabels)
                    ->required()
                    ->native(false),
                KeyValue::make('attributes_json')
                    ->label('Service attributes')
                    ->keyLabel('Key')
                    ->valueLabel('Value')
                    ->addActionLabel('Add attribute')
                    ->default([])
                    ->columnSpanFull(),
            ]);
    }
}
