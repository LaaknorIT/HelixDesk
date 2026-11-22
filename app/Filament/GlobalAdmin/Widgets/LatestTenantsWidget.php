<?php

namespace App\Filament\GlobalAdmin\Widgets;

use App\Filament\GlobalAdmin\Resources\Tenants\Tables\TenantsTable;
use App\Filament\GlobalAdmin\Resources\Tenants\TenantResource;
use App\Models\Tenant;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class LatestTenantsWidget extends TableWidget
{
    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('Tenants'))
            ->query(fn (): Builder => Tenant::query()->withCount('users'))
            ->columns([
                TextColumn::make('name')
                    ->label(__('Name')),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => TenantsTable::statusLabel($state))
                    ->color(fn (?string $state): string => TenantsTable::statusColor($state)),
                TextColumn::make('users_count')
                    ->label(__('Users')),
                TextColumn::make('created_at')
                    ->label(__('Created'))
                    ->since()
                    ->dateTimeTooltip(),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([5])
            ->recordUrl(fn (Tenant $record): string => TenantResource::getUrl('edit', ['record' => $record]));
    }
}
