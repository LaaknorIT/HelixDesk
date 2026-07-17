<?php

namespace App\Filament\Agent\Resources\WorkflowTransitions\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class WorkflowTransitionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('from_status')
                    ->label('From')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('to_status')
                    ->label('To')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('required_permission')
                    ->label('Permission')
                    ->placeholder('Anyone (incl. end user)')
                    ->toggleable(),
            ])
            ->defaultSort('from_status')
            ->filters([])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
