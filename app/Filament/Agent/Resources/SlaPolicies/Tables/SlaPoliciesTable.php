<?php

namespace App\Filament\Agent\Resources\SlaPolicies\Tables;

use App\Enums\SlaTargetType;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SlaPoliciesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                IconColumn::make('is_active')
                    ->boolean(),
                TextColumn::make('target_type')
                    ->badge()
                    ->formatStateUsing(fn (SlaTargetType|string $state): string => $state instanceof SlaTargetType
                        ? str($state->name)->headline()->toString()
                        : str((string) $state)->headline()->toString())
                    ->sortable(),
                TextColumn::make('first_response_minutes')
                    ->label('First response (min)')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('resolution_minutes')
                    ->label('Resolution (min)')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('match_priority')
                    ->sortable(),
            ])
            ->filters([])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('match_priority', 'desc');
    }
}
