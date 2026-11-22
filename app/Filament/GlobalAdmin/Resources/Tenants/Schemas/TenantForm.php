<?php

namespace App\Filament\GlobalAdmin\Resources\Tenants\Schemas;

use App\Filament\GlobalAdmin\Resources\Tenants\Tables\TenantsTable;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class TenantForm
{
    /**
     * Regions a tenant's data can live in. HelixDesk is EU-only for now.
     *
     * @return array<string, string>
     */
    public static function regionOptions(): array
    {
        return [
            'eu' => __('EU'),
        ];
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label(__('Tenant name'))
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (Get $get, Set $set, ?string $old, ?string $state, string $operation): void {
                        // Suggest a slug on create, until the admin edits it themselves.
                        if ($operation !== 'create') {
                            return;
                        }

                        if (blank($get('slug')) || $get('slug') === Str::slug((string) $old)) {
                            $set('slug', Str::slug((string) $state));
                        }
                    }),
                TextInput::make('slug')
                    ->label(__('Web address name'))
                    ->required()
                    ->maxLength(255)
                    ->alphaDash()
                    ->unique(ignoreRecord: true)
                    ->prefix(fn (): string => rtrim((string) config('app.url'), '/').'/agent/')
                    ->helperText(__('Part of the address agents and end users use. Changing it later breaks saved links.')),
                Select::make('status')
                    ->label(__('Status'))
                    ->required()
                    ->default('active')
                    ->options(TenantsTable::statusOptions())
                    ->helperText(__('Read only: users can sign in and look, but not change anything. Disabled: nobody can sign in.')),
                Select::make('region')
                    ->label(__('Data region'))
                    ->required()
                    ->default('eu')
                    ->options(static::regionOptions()),
            ]);
    }
}
