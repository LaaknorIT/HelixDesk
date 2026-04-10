<?php

namespace App\Filament\Agent\Resources\Documents\RelationManagers;

use App\Filament\Agent\Resources\Incidents\IncidentResource;
use Filament\Actions\AttachAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class IncidentsRelationManager extends RelationManager
{
    protected static string $relationship = 'incidents';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->columns([
                TextColumn::make('title')
                    ->searchable(),
                TextColumn::make('status')
                    ->badge(),
            ])
            ->headerActions([
                AttachAction::make()
                    ->visible(fn (): bool => auth()->user()?->hasPermissionTo('document.update') === true),
            ])
            ->recordActions([
                ViewAction::make()
                    ->url(fn ($record): string => IncidentResource::getUrl('view', ['record' => $record])),
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
