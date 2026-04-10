<?php

namespace App\Filament\Agent\Resources\DashboardLayouts\Schemas;

use App\Models\DashboardLayout;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class DashboardLayoutForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Hidden::make('tenant_id')
                    ->default(fn (): ?int => Filament::getTenant()?->getKey()),
                Select::make('scope')
                    ->required()
                    ->options([
                        DashboardLayout::SCOPE_ROLE_DEFAULT => 'Role default',
                        DashboardLayout::SCOPE_USER_OVERRIDE => 'User override',
                    ])
                    ->live(),
                Select::make('role_name')
                    ->label('Role')
                    ->options(
                        collect(config('helixdesk_permissions.tenant_roles', []))
                            ->mapWithKeys(fn (array $role): array => [$role['name'] => $role['name']])
                    )
                    ->required(fn (Get $get): bool => $get('scope') === DashboardLayout::SCOPE_ROLE_DEFAULT)
                    ->visible(fn (Get $get): bool => $get('scope') === DashboardLayout::SCOPE_ROLE_DEFAULT),
                Select::make('user_id')
                    ->label('User')
                    ->relationship(
                        'user',
                        'name',
                        modifyQueryUsing: fn (Builder $query): Builder => $query
                            ->where('tenant_id', Filament::getTenant()?->getKey())
                            ->where('type', 'agent'),
                    )
                    ->searchable()
                    ->preload()
                    ->required(fn (Get $get): bool => $get('scope') === DashboardLayout::SCOPE_USER_OVERRIDE)
                    ->visible(fn (Get $get): bool => $get('scope') === DashboardLayout::SCOPE_USER_OVERRIDE),
                Repeater::make('layout_json')
                    ->label('Widgets layout')
                    ->schema([
                        TextInput::make('widget')
                            ->required()
                            ->maxLength(100),
                        TextInput::make('x')
                            ->numeric()
                            ->required(),
                        TextInput::make('y')
                            ->numeric()
                            ->required(),
                        TextInput::make('w')
                            ->numeric()
                            ->required(),
                        TextInput::make('h')
                            ->numeric()
                            ->required(),
                    ])
                    ->defaultItems(1)
                    ->columns(5)
                    ->required(),
                TagsInput::make('locked_widgets')
                    ->placeholder('my_incidents')
                    ->default([])
                    ->helperText('Widget identifiers users cannot remove in personal overrides.'),
                Toggle::make('is_active')
                    ->default(true)
                    ->required(),
            ]);
    }
}
