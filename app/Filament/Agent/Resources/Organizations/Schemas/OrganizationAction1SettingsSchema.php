<?php

namespace App\Filament\Agent\Resources\Organizations\Schemas;

use App\Enums\Action1ApiRegion;
use App\Filament\Agent\Resources\Organizations\Pages\EditOrganizationIntegration;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\UnorderedList;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Livewire\Component;

class OrganizationAction1SettingsSchema
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Wizard::make([
                    Step::make(__('Overview'))
                        ->description(__('What HelixDesk does with Action1.'))
                        ->icon(Heroicon::OutlinedInformationCircle)
                        ->schema([
                            Callout::make(__('Action1 endpoint management'))
                                ->icon(Heroicon::OutlinedComputerDesktop)
                                ->info()
                                ->description(__('HelixDesk reads endpoints, installed software, missing updates and (optionally) alerts from one Action1 organization, and links them to assets for this customer. HelixDesk never changes anything in Action1.')),
                            Text::make(__('You will need:')),
                            UnorderedList::make([
                                Text::make(__('An Action1 API credential (client ID and client secret), created under Settings → API Credentials in Action1.')),
                                Text::make(__('The ID of the Action1 organization that belongs to this customer.')),
                            ]),
                        ]),
                    Step::make(__('Credentials'))
                        ->description(__('Where to connect and how to sign in.'))
                        ->icon(Heroicon::OutlinedKey)
                        ->schema([
                            Select::make('action1_region')
                                ->label(__('API region'))
                                ->options(Action1ApiRegion::options())
                                ->default(Action1ApiRegion::Us->value)
                                ->required()
                                ->live()
                                ->helperText(__('Pick the region your Action1 console runs in (app.action1.com or app.eu.action1.com).')),
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
                        ]),
                    Step::make(__('Customer scope'))
                        ->description(__('Which Action1 endpoints belong to this customer.'))
                        ->icon(Heroicon::OutlinedBuildingOffice)
                        ->schema([
                            TextInput::make('action1_organization_id')
                                ->label(__('Action1 organization ID'))
                                ->maxLength(64)
                                ->required()
                                ->helperText(__('Shown in the Action1 console URL and under organization settings.')),
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
                    Step::make(__('Capabilities'))
                        ->description(__('Choose what to sync.'))
                        ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
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
                                ->helperText(__('Requires that the API credential may read audit events. If it may not, HelixDesk shows a warning and keeps syncing the rest.'))
                                ->default(false),
                        ]),
                    Step::make(__('Enable'))
                        ->description(__('Turn on background sync.'))
                        ->icon(Heroicon::OutlinedPower)
                        ->schema([
                            Toggle::make('action1_enabled')
                                ->label(__('Enable Action1 integration for this organization'))
                                ->helperText(__('When saved and enabled, HelixDesk queues a first sync right away. Results and any problems show up on the Integrations page.'))
                                ->default(false),
                        ]),
                ])
                    ->skippable(fn (Component $livewire): bool => $livewire instanceof EditOrganizationIntegration)
                    ->columnSpanFull(),
            ]);
    }
}
