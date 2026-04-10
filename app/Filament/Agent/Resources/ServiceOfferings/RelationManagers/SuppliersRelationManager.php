<?php

namespace App\Filament\Agent\Resources\ServiceOfferings\RelationManagers;

use App\Filament\Agent\Actions\AttachOfferingSupplierAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SuppliersRelationManager extends RelationManager
{
    protected static string $relationship = 'suppliers';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('pivot.role')
                    ->label('Role')
                    ->badge(),
                TextColumn::make('pivot.notes')
                    ->label('Notes')
                    ->placeholder('—')
                    ->limit(40),
            ])
            ->headerActions([
                AttachOfferingSupplierAction::make()
                    ->visible(fn (): bool => $this->canAttachSuppliers()),
            ])
            ->recordActions([
                DetachAction::make()
                    ->visible(fn (): bool => $this->canAttachSuppliers()),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DetachBulkAction::make()
                        ->visible(fn (): bool => $this->canAttachSuppliers()),
                ]),
            ]);
    }

    private function canAttachSuppliers(): bool
    {
        $user = auth()->user();

        if ($user === null) {
            return false;
        }

        return $user->hasPermissionTo('service_offering.update')
            && $user->hasPermissionTo('supplier.read');
    }
}
