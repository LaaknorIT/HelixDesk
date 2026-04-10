<?php

namespace App\Filament\Agent\Resources\Assets\RelationManagers;

use App\Filament\Agent\Actions\AttachServiceOfferingToAssetAction;
use App\Filament\Agent\Resources\ServiceOfferings\ServiceOfferingResource;
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
                TextColumn::make('organization.name')
                    ->label('Organization'),
                TextColumn::make('kind')
                    ->badge(),
                TextColumn::make('pivot.role')
                    ->label('Role')
                    ->badge(),
            ])
            ->headerActions([
                AttachServiceOfferingToAssetAction::make()
                    ->visible(fn (): bool => $this->canAttachOfferings()),
            ])
            ->recordActions([
                ViewAction::make()
                    ->url(fn ($record): string => ServiceOfferingResource::getUrl('edit', ['record' => $record])),
                DetachAction::make()
                    ->visible(fn (): bool => $this->canAttachOfferings()),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DetachBulkAction::make()
                        ->visible(fn (): bool => $this->canAttachOfferings()),
                ]),
            ]);
    }

    private function canAttachOfferings(): bool
    {
        $user = auth()->user();

        if ($user === null) {
            return false;
        }

        return $user->hasPermissionTo('asset.update')
            && $user->hasPermissionTo('service_offering.read');
    }
}
