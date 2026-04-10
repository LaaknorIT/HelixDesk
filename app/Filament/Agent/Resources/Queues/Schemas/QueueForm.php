<?php

namespace App\Filament\Agent\Resources\Queues\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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
            ]);
    }
}
