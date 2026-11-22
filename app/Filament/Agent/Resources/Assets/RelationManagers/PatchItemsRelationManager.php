<?php

namespace App\Filament\Agent\Resources\Assets\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PatchItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'patchItems';

    protected static ?string $title = 'Patch items';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('severity')
            ->columns([
                TextColumn::make('kb_id')
                    ->label(__('KB'))
                    ->searchable(),
                TextColumn::make('cve_id')
                    ->label(__('CVE'))
                    ->placeholder(__('—'))
                    ->searchable(),
                TextColumn::make('title')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('severity')
                    ->badge(),
                TextColumn::make('approval_status')
                    ->badge()
                    ->placeholder(__('—')),
                TextColumn::make('provider')
                    ->badge()
                    ->toggleable(),
                TextColumn::make('last_seen_at')
                    ->dateTime()
                    ->placeholder(__('—'))
                    ->toggleable(),
            ])
            ->paginated([10, 25, 50]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
