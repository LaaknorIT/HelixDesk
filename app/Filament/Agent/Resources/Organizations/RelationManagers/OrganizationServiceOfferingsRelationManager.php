<?php

namespace App\Filament\Agent\Resources\Organizations\RelationManagers;

use App\Filament\Agent\Resources\ServiceOfferings\ServiceOfferingResource;
use App\Models\Organization;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class OrganizationServiceOfferingsRelationManager extends RelationManager
{
    protected static string $relationship = 'serviceOfferings';

    protected static ?string $title = 'Service offerings';

    protected static bool $shouldSkipAuthorization = true;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Organization
            && $ownerRecord->acceptsServiceOfferings();
    }

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
                CreateAction::make()
                    ->url(fn (): string => ServiceOfferingResource::getUrl('create', [
                        'organization_id' => $this->ownerRecord->getKey(),
                    ]))
                    ->visible(fn (): bool => auth()->user()?->hasPermissionTo('service_offering.create') ?? false),
            ])
            ->recordActions([
                EditAction::make()
                    ->url(fn ($record): string => ServiceOfferingResource::getUrl('edit', ['record' => $record]))
                    ->visible(fn (): bool => auth()->user()?->hasPermissionTo('service_offering.update') ?? false),
            ]);
    }
}
