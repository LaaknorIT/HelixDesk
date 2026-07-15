<?php

namespace App\Filament\Agent\Resources\Organizations\Schemas;

use App\Enums\Action1ApiRegion;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class OrganizationAction1SettingsSchema
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Action1 connection'))
                    ->schema([
                        Select::make('action1_region')
                            ->label(__('API region'))
                            ->options(Action1ApiRegion::options())
                            ->default(Action1ApiRegion::Us->value)
                            ->required()
                            ->live(),
                        TextInput::make('action1_api_base_url')
                            ->label(__('API base URL'))
                            ->maxLength(255)
                            ->default(config('helixdesk_integrations.action1.default_api_base_url'))
                            ->helperText(__('US or EU Action1 API root; preset from region unless customized.')),
                        TextInput::make('action1_client_id')
                            ->label(__('Client ID'))
                            ->maxLength(255)
                            ->required(),
                        TextInput::make('action1_client_secret')
                            ->label(__('Client secret'))
                            ->password()
                            ->revealable()
                            ->maxLength(512)
                            ->default('')
                            ->helperText(__('Leave blank on edit to keep the stored secret.')),
                        TextInput::make('action1_api_key')
                            ->label(__('API key (optional)'))
                            ->password()
                            ->revealable()
                            ->maxLength(512)
                            ->default('')
                            ->helperText(__('Alternative to client secret when using API key auth.')),
                        TextInput::make('action1_organization_id')
                            ->label(__('Action1 organization ID'))
                            ->maxLength(64)
                            ->required(),
                        TextInput::make('action1_organization_name')
                            ->label(__('Action1 organization name'))
                            ->maxLength(255)
                            ->default(''),
                        TextInput::make('action1_group_id')
                            ->label(__('Action1 group ID (optional)'))
                            ->maxLength(64)
                            ->default('')
                            ->helperText(__('When set, only endpoints in this Action1 group are synced for this customer.')),
                    ]),
                Section::make(__('Sync scopes'))
                    ->schema([
                        Toggle::make('action1_scope_endpoints')
                            ->label(__('Endpoints / inventory'))
                            ->default(true),
                        Toggle::make('action1_scope_software')
                            ->label(__('Installed software'))
                            ->default(true),
                        Toggle::make('action1_scope_patches')
                            ->label(__('Missing updates / patch status'))
                            ->default(true),
                        Toggle::make('action1_scope_alerts')
                            ->label(__('Alerts (audit events poll)'))
                            ->default(false),
                    ]),
                Section::make(__('Enable'))
                    ->schema([
                        Toggle::make('action1_enabled')
                            ->label(__('Enable Action1 integration for this organization'))
                            ->default(false),
                    ]),
            ]);
    }
}
