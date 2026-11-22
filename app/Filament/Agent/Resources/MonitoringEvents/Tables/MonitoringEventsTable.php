<?php

namespace App\Filament\Agent\Resources\MonitoringEvents\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MonitoringEventsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('last_seen_at')
                    ->label(__('Last seen'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('severity')
                    ->badge()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('occurrence_count')
                    ->label(__('Count'))
                    ->sortable(),
                TextColumn::make('source.slug')
                    ->label(__('Source')),
                TextColumn::make('message')
                    ->label(__('Message'))
                    ->limit(60)
                    ->tooltip(fn ($record): ?string => $record->message ?? null),
                TextColumn::make('external_reference')
                    ->label(__('External ref'))
                    ->placeholder(__('—'))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('last_seen_at', 'desc')
            ->filters([
                //
            ])
            ->recordActions([
                //
            ])
            ->toolbarActions([
                //
            ]);
    }
}
