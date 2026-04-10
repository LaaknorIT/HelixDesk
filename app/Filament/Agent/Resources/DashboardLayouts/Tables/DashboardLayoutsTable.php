<?php

namespace App\Filament\Agent\Resources\DashboardLayouts\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class DashboardLayoutsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('scope')
                    ->badge()
                    ->sortable(),
                TextColumn::make('role_name')
                    ->label('Role')
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('user.name')
                    ->label('User')
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('layout_json')
                    ->label('Widgets')
                    ->state(fn ($record): string => (string) count($record->layout_json ?? [])),
                IconColumn::make('is_active')
                    ->boolean(),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('scope')
                    ->options([
                        'role_default' => 'Role default',
                        'user_override' => 'User override',
                    ]),
            ])
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
