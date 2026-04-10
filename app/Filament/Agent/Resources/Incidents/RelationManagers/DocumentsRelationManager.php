<?php

namespace App\Filament\Agent\Resources\Incidents\RelationManagers;

use App\Support\HelixdeskIncidentAuthorization;
use Filament\Actions\AttachAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Actions\EditAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'documents';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->columns([
                TextColumn::make('title')
                    ->searchable(),
                TextColumn::make('doc_type')
                    ->badge(),
                TextColumn::make('status')
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

                        return $user->hasPermissionTo('document.update')
                            && HelixdeskIncidentAuthorization::hasIncidentPermission($user, 'update', (int) $incident->queue_id);
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (): bool => auth()->user()?->hasPermissionTo('document.update') === true),
                DetachAction::make()
                    ->visible(function (): bool {
                        $user = auth()->user();
                        $incident = $this->getOwnerRecord();

                        if ($user === null || $incident->queue_id === null) {
                            return false;
                        }

                        return $user->hasPermissionTo('document.update')
                            && HelixdeskIncidentAuthorization::hasIncidentPermission($user, 'update', (int) $incident->queue_id);
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

                            return $user->hasPermissionTo('document.update')
                                && HelixdeskIncidentAuthorization::hasIncidentPermission($user, 'update', (int) $incident->queue_id);
                        }),
                ]),
            ]);
    }
}
