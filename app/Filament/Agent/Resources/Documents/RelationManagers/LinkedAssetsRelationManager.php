<?php

namespace App\Filament\Agent\Resources\Documents\RelationManagers;

use App\Filament\Agent\Resources\Assets\AssetResource;
use Filament\Actions\AttachAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LinkedAssetsRelationManager extends RelationManager
{
    protected static string $relationship = 'assets';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('assetType.name')
                    ->label('Type'),
                TextColumn::make('lifecycle_status')
                    ->badge(),
            ])
            ->headerActions([
                AttachAction::make()
                    ->visible(fn (): bool => auth()->user()?->hasPermissionTo('document.update') === true),
            ])
            ->recordActions([
                ViewAction::make()
                    ->url(fn ($record): string => AssetResource::getUrl('edit', ['record' => $record])),
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
