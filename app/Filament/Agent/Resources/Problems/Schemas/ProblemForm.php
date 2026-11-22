<?php

namespace App\Filament\Agent\Resources\Problems\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ProblemForm
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
                Textarea::make('root_cause')
                    ->default(null)
                    ->columnSpanFull(),
                Textarea::make('workaround')
                    ->default(null)
                    ->columnSpanFull(),
                Textarea::make('permanent_fix')
                    ->default(null)
                    ->columnSpanFull(),
                Select::make('status')
                    ->required()
                    ->options([
                        'open' => __('Open'),
                        'under_investigation' => __('Under investigation'),
                        'resolved' => __('Resolved'),
                        'closed' => __('Closed'),
                    ])
                    ->default('open'),
            ]);
    }
}
