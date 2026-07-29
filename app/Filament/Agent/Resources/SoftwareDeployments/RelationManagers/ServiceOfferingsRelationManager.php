<?php

namespace App\Filament\Agent\Resources\SoftwareDeployments\RelationManagers;

use App\Filament\Agent\Resources\ServiceOfferings\ServiceOfferingResource;
use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Actions\EditAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ServiceOfferingsRelationManager extends RelationManager
{
    protected static string $relationship = 'serviceOfferings';

    protected static ?string $title = 'Service offerings';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('kind')
                    ->badge(),
                TextColumn::make('code')
                    ->placeholder('—'),
            ])
            ->headerActions([
                AttachAction::make()
                    ->preloadRecordSelect()
                    ->visible(fn (): bool => auth()->user()?->hasPermissionTo('service_offering.update') ?? false),
            ])
            ->recordActions([
                EditAction::make()
                    ->url(fn ($record): string => ServiceOfferingResource::getUrl('edit', ['record' => $record]))
                    ->visible(fn (): bool => auth()->user()?->hasPermissionTo('service_offering.update') ?? false),
                DetachAction::make()
                    ->visible(fn (): bool => auth()->user()?->hasPermissionTo('service_offering.update') ?? false),
            ]);
    }
}
