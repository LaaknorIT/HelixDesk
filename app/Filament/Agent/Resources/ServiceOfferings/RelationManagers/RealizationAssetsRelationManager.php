<?php

namespace App\Filament\Agent\Resources\ServiceOfferings\RelationManagers;

use App\Filament\Agent\Actions\AttachOfferingAssetAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RealizationAssetsRelationManager extends RelationManager
{
    protected static string $relationship = 'realizationAssets';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('assetType.name')
                    ->label('Type'),
                TextColumn::make('pivot.role')
                    ->label('Role')
                    ->badge(),
                TextColumn::make('lifecycle_status')
                    ->badge(),
            ])
            ->headerActions([
                AttachOfferingAssetAction::make()
                    ->visible(fn (): bool => $this->canAttachAssets()),
            ])
            ->recordActions([
                DetachAction::make()
                    ->visible(fn (): bool => $this->canAttachAssets()),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DetachBulkAction::make()
                        ->visible(fn (): bool => $this->canAttachAssets()),
                ]),
            ]);
    }

    private function canAttachAssets(): bool
    {
        $user = auth()->user();

        if ($user === null) {
            return false;
        }

        return $user->hasPermissionTo('service_offering.update')
            && $user->hasPermissionTo('asset.read');
    }
}
