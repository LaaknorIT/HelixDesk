<?php

namespace App\Filament\Agent\Resources\Assets\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SoftwareInstallationsRelationManager extends RelationManager
{
    protected static string $relationship = 'softwareInstallations';

    protected static ?string $title = 'Software installations';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('version')
                    ->placeholder(__('—')),
                TextColumn::make('publisher')
                    ->placeholder(__('—'))
                    ->toggleable(),
                TextColumn::make('provider')
                    ->badge(),
                TextColumn::make('source')
                    ->badge(),
                TextColumn::make('install_date')
                    ->date()
                    ->placeholder(__('—'))
                    ->toggleable(),
                TextColumn::make('last_seen_at')
                    ->dateTime()
                    ->placeholder(__('—')),
            ])
            ->paginated([10, 25, 50]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
