<?php

namespace App\Filament\Agent\Resources\Assets\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AttributeProvenanceRelationManager extends RelationManager
{
    protected static string $relationship = 'attributeProvenance';

    protected static ?string $title = 'Attribute provenance';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('synced_at', 'desc')
            ->columns([
                TextColumn::make('attribute')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('provider')
                    ->badge(),
                TextColumn::make('organizationIntegration.type')
                    ->label('Integration')
                    ->placeholder('—'),
                TextColumn::make('synced_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->paginated([10, 25, 50]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
