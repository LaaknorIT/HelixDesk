<?php

namespace App\Filament\GlobalAdmin\Resources\Tenants\Tables;

use App\Filament\GlobalAdmin\Resources\Tenants\TenantResource;
use App\Models\Tenant;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class TenantsTable
{
    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return [
            'active' => __('Active'),
            'readonly' => __('Read only'),
            'disabled' => __('Disabled'),
        ];
    }

    public static function statusLabel(?string $status): string
    {
        return static::statusOptions()[$status] ?? (string) $status;
    }

    public static function statusColor(?string $status): string
    {
        return match ($status) {
            'active' => 'success',
            'readonly' => 'warning',
            'disabled' => 'danger',
            default => 'gray',
        };
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('slug')
                    ->label(__('Web address name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => static::statusLabel($state))
                    ->color(fn (?string $state): string => static::statusColor($state))
                    ->sortable(),
                TextColumn::make('region')
                    ->label(__('Data region'))
                    ->formatStateUsing(fn (?string $state): string => mb_strtoupper((string) $state))
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label(__('Created'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options(static::statusOptions()),
            ])
            ->recordUrl(fn (Tenant $record): string => TenantResource::getUrl('edit', ['record' => $record]))
            ->recordActions([
                EditAction::make(),
            ])
            // No bulk delete: removing a tenant deletes a whole customer's helpdesk. Delete one at a time
            // from the tenant's own page, where the name must be confirmed.
            ->toolbarActions([]);
    }
}
