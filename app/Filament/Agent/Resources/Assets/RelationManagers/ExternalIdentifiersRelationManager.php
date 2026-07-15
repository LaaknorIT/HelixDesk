<?php

namespace App\Filament\Agent\Resources\Assets\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ExternalIdentifiersRelationManager extends RelationManager
{
    protected static string $relationship = 'externalIdentifiers';

    protected static ?string $title = 'External identifiers';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('provider')
            ->columns([
                TextColumn::make('provider')
                    ->badge(),
                TextColumn::make('identifier_type')
                    ->label('Type')
                    ->badge(),
                TextColumn::make('external_id')
                    ->label('External ID')
                    ->searchable()
                    ->copyable(),
                IconColumn::make('is_primary')
                    ->boolean()
                    ->label('Primary'),
                TextColumn::make('last_seen_at')
                    ->dateTime()
                    ->placeholder('—'),
            ])
            ->paginated([10, 25, 50]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
