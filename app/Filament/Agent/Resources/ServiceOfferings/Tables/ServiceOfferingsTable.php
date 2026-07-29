<?php

namespace App\Filament\Agent\Resources\ServiceOfferings\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ServiceOfferingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('organizations.name')
                    ->label('Organizations')
                    ->badge()
                    ->separator(','),
                TextColumn::make('kind')
                    ->badge()
                    ->sortable(),
                TextColumn::make('code')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('software_deployments_count')
                    ->counts('softwareDeployments')
                    ->label('Deployments')
                    ->toggleable(),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
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
