<?php

namespace App\Filament\Agent\Resources\Documents\RelationManagers;

use App\Filament\Agent\Resources\ServiceOfferings\ServiceOfferingResource;
use Filament\Actions\AttachAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ServiceOfferingsRelationManager extends RelationManager
{
    protected static string $relationship = 'serviceOfferings';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('kind')
                    ->badge(),
                TextColumn::make('organization.name')
                    ->label('Organization'),
            ])
            ->headerActions([
                AttachAction::make()
                    ->visible(fn (): bool => auth()->user()?->hasPermissionTo('document.update') === true),
            ])
            ->recordActions([
                ViewAction::make()
                    ->url(fn ($record): string => ServiceOfferingResource::getUrl('edit', ['record' => $record])),
                DetachAction::make()
                    ->visible(fn (): bool => auth()->user()?->hasPermissionTo('document.update') === true),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DetachBulkAction::make()
                        ->visible(fn (): bool => auth()->user()?->hasPermissionTo('document.update') === true),
                ]),
            ]);
    }
}
