<?php

namespace App\Filament\Agent\Resources\SoftwareProducts\RelationManagers;

use App\Filament\Agent\Resources\Documents\DocumentResource;
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

    protected static ?string $title = 'Partner documentation';

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
                    ->visible(fn (): bool => $this->canManageDocuments()),
            ])
            ->recordActions([
                EditAction::make()
                    ->url(fn ($record): string => DocumentResource::getUrl('edit', ['record' => $record]))
                    ->visible(fn (): bool => auth()->user()?->hasPermissionTo('document.update') === true),
                DetachAction::make()
                    ->visible(fn (): bool => $this->canManageDocuments()),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DetachBulkAction::make()
                        ->visible(fn (): bool => $this->canManageDocuments()),
                ]),
            ]);
    }

    private function canManageDocuments(): bool
    {
        $user = auth()->user();

        if ($user === null) {
            return false;
        }

        return $user->hasPermissionTo('document.update')
            && $user->hasPermissionTo('software_product.update');
    }
}
