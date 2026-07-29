<?php

namespace App\Filament\Agent\Resources\SoftwareProducts\RelationManagers;

use App\Filament\Agent\Resources\SoftwareDeployments\SoftwareDeploymentResource;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DeploymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'deployments';

    protected static ?string $title = 'Customer deployments';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('organization.name')
                    ->label('Organization'),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('environment')
                    ->badge()
                    ->placeholder('—'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->url(fn (): string => SoftwareDeploymentResource::getUrl('create', [
                        'software_product_id' => $this->getOwnerRecord()->getKey(),
                    ]))
                    ->visible(fn (): bool => auth()->user()?->hasPermissionTo('software_deployment.create') ?? false),
            ])
            ->recordActions([
                EditAction::make()
                    ->url(fn ($record): string => SoftwareDeploymentResource::getUrl('edit', ['record' => $record]))
                    ->visible(fn (): bool => auth()->user()?->hasPermissionTo('software_deployment.update') ?? false),
            ]);
    }
}
