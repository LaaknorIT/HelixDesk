<?php

namespace App\Filament\Agent\Resources\SoftwareDeployments\Tables;

use App\Enums\SoftwareDeploymentStatus;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SoftwareDeploymentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('softwareProduct.name')
                    ->label('Product')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('organization.name')
                    ->label('Organization')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('environment')
                    ->badge()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('licenses_count')
                    ->counts('licenses')
                    ->label('Licenses')
                    ->toggleable(),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(SoftwareDeploymentStatus::cases())
                        ->mapWithKeys(fn (SoftwareDeploymentStatus $case): array => [
                            $case->value => $case->label(),
                        ])
                        ->all()),
                SelectFilter::make('software_product_id')
                    ->label('Product')
                    ->relationship('softwareProduct', 'name'),
                SelectFilter::make('organization_id')
                    ->label('Organization')
                    ->relationship('organization', 'name'),
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
