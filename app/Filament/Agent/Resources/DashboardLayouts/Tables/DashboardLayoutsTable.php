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
                    ->label(__('Role'))
                    ->placeholder(__('-'))
                    ->sortable(),
                TextColumn::make('user.name')
                    ->label(__('User'))
                    ->placeholder(__('-'))
                    ->searchable(),
                TextColumn::make('layout_json')
                    ->label(__('Widgets'))
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
                        'role_default' => __('Role default'),
                        'user_override' => __('User override'),
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
