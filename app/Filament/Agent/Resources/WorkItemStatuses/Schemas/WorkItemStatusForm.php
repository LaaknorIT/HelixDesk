<?php

namespace App\Filament\Agent\Resources\WorkItemStatuses\Schemas;

use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class WorkItemStatusForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('sort_order')
                    ->numeric()
                    ->default(0)
                    ->required(),
                Toggle::make('is_default_open')
                    ->label('Default open status')
                    ->default(false),
                Toggle::make('pauses_sla')
                    ->label('Pauses SLA timer')
                    ->default(false),
                Toggle::make('sets_resolved_at')
                    ->label('Sets resolved timestamp')
                    ->default(false),
                Toggle::make('sets_closed_at')
                    ->label('Sets closed timestamp')
                    ->default(false),
                ColorPicker::make('color')
                    ->nullable(),
            ]);
    }
}
