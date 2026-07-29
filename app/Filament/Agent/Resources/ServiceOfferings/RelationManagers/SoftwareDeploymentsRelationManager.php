<?php

namespace App\Filament\Agent\Resources\ServiceOfferings\RelationManagers;

use App\Filament\Agent\Resources\SoftwareDeployments\SoftwareDeploymentResource;
use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Actions\EditAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SoftwareDeploymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'softwareDeployments';

    protected static ?string $title = 'Software deployments';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('softwareProduct.name')
                    ->label('Product'),
                TextColumn::make('organization.name')
                    ->label('Organization'),
                TextColumn::make('status')
                    ->badge(),
            ])
            ->headerActions([
                AttachAction::make()
                    ->preloadRecordSelect()
                    ->visible(fn (): bool => auth()->user()?->hasPermissionTo('service_offering.update') ?? false),
            ])
            ->recordActions([
                EditAction::make()
                    ->url(fn ($record): string => SoftwareDeploymentResource::getUrl('edit', ['record' => $record]))
                    ->visible(fn (): bool => auth()->user()?->hasPermissionTo('software_deployment.update') ?? false),
                DetachAction::make()
                    ->visible(fn (): bool => auth()->user()?->hasPermissionTo('service_offering.update') ?? false),
            ]);
    }
}
