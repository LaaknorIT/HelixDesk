<?php

namespace App\Filament\Agent\Resources\Assets\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AssetsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('assetType.name')
                    ->label('Type')
                    ->sortable(),
                TextColumn::make('asset_identifier')
                    ->label('Tag')
                    ->searchable(),
                TextColumn::make('lifecycle_status')
                    ->label('Lifecycle')
                    ->badge()
                    ->sortable(),
                TextColumn::make('identity_resolution_status')
                    ->label('Identity')
                    ->badge()
                    ->toggleable(),
                TextColumn::make('primary_hostname')
                    ->label('Hostname')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('operating_system')
                    ->label('OS')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('organization.name')
                    ->label('Organization')
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
