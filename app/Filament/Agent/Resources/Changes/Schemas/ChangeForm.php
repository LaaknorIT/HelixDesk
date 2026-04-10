<?php

namespace App\Filament\Agent\Resources\Changes\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ChangeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('title')
                    ->required()
                    ->maxLength(255),
                Textarea::make('description')
                    ->default(null)
                    ->columnSpanFull(),
                TextInput::make('risk_id')
                    ->label('Risk ID')
                    ->numeric()
                    ->default(null)
                    ->helperText('Optional link to a risk record (when the risk module is enabled).'),
                Textarea::make('implementation_plan')
                    ->default(null)
                    ->columnSpanFull(),
                Textarea::make('rollback_plan')
                    ->default(null)
                    ->columnSpanFull(),
                DateTimePicker::make('scheduled_start_at'),
                DateTimePicker::make('scheduled_end_at'),
            ]);
    }
}
