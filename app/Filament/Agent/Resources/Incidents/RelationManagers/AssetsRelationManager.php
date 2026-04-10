<?php

namespace App\Filament\Agent\Resources\Incidents\RelationManagers;

use App\Support\HelixdeskIncidentAuthorization;
use Filament\Actions\AttachAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AssetsRelationManager extends RelationManager
{
    protected static string $relationship = 'assets';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Name')
                    ->searchable(),
                TextColumn::make('assetType.name')
                    ->label('Type'),
                TextColumn::make('lifecycle_status')
                    ->badge(),
            ])
            ->headerActions([
                AttachAction::make()
                    ->visible(function (): bool {
                        $user = auth()->user();
                        $incident = $this->getOwnerRecord();

                        if ($user === null || $incident->queue_id === null) {
                            return false;
                        }

                        return HelixdeskIncidentAuthorization::hasIncidentPermission($user, 'update', (int) $incident->queue_id)
                            && $user->hasPermissionTo('asset.update');
                    }),
            ])
            ->recordActions([
                DetachAction::make()
                    ->visible(function (): bool {
                        $user = auth()->user();
                        $incident = $this->getOwnerRecord();

                        if ($user === null || $incident->queue_id === null) {
                            return false;
                        }

                        return HelixdeskIncidentAuthorization::hasIncidentPermission($user, 'update', (int) $incident->queue_id);
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DetachBulkAction::make()
                        ->visible(function (): bool {
                            $user = auth()->user();
                            $incident = $this->getOwnerRecord();

                            if ($user === null || $incident->queue_id === null) {
                                return false;
                            }

                            return HelixdeskIncidentAuthorization::hasIncidentPermission($user, 'update', (int) $incident->queue_id);
                        }),
                ]),
            ]);
    }
}
