<?php

namespace App\Filament\Agent\Resources\Assets\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PatchStatusesRelationManager extends RelationManager
{
    protected static string $relationship = 'patchStatuses';

    protected static ?string $title = 'Patch status';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('provider')
            ->columns([
                TextColumn::make('provider')
                    ->badge(),
                TextColumn::make('compliance_status')
                    ->label('Compliance')
                    ->badge(),
                TextColumn::make('missing_critical_count')
                    ->label('Critical missing')
                    ->numeric(),
                TextColumn::make('missing_other_count')
                    ->label('Other missing')
                    ->numeric(),
                TextColumn::make('last_assessed_at')
                    ->dateTime()
                    ->placeholder('—'),
            ])
            ->paginated([10, 25, 50]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
