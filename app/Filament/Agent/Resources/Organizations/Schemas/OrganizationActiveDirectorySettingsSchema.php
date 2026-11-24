<?php

namespace App\Filament\Agent\Resources\Organizations\Schemas;

use App\Filament\Agent\Resources\Organizations\Pages\EditOrganizationIntegration;
use App\Support\OrganizationIntegrationSettings;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
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

class OrganizationActiveDirectorySettingsSchema
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Wizard::make([
                    Step::make(__('Overview'))
                        ->description(__('How HelixDesk reads Active Directory.'))
                        ->icon(Heroicon::OutlinedInformationCircle)
                        ->schema([
                            Callout::make(__('Active Directory through an on-premises collector'))
                                ->icon(Heroicon::OutlinedServerStack)
                                ->info()
                                ->description(__('Active Directory is usually not reachable from the internet. A small PowerShell collector runs as a scheduled task on a domain-joined machine, reads computer objects and sends them to HelixDesk over HTTPS. It only needs outbound access to HelixDesk, and no AD passwords are stored in HelixDesk.')),
                            Text::make(__('You will need:')),
                            UnorderedList::make([
                                Text::make(__('A domain-joined Windows machine (a member server is fine) with PowerShell 5.1 or later.')),
                                Text::make(__('An account that can read computer objects. A normal domain user is enough; a group managed service account (gMSA) is recommended.')),
                            ]),
                            Text::make(__('After you save, you create the collector key and download the collector script on the next page.'))
                                ->color('gray'),
                        ]),
                    Step::make(__('Domain'))
                        ->description(__('Which domain and which OUs to read.'))
                        ->icon(Heroicon::OutlinedBuildingOffice)
                        ->schema([
                            TextInput::make('ad_domain_fqdn')
                                ->label(__('Domain name'))
                                ->placeholder('corp.example.com')
                                ->required()
                                ->maxLength(255)
                                ->helperText(__('One integration per domain. Add another integration for each additional domain.')),
                            Textarea::make('ad_search_bases')
                                ->label(__('OUs to include (optional)'))
                                ->placeholder("OU=Computers,DC=corp,DC=example,DC=com\nOU=Servers,DC=corp,DC=example,DC=com")
                                ->rows(3)
                                ->helperText(__('One distinguished name per line. Leave empty to read the whole domain.')),
                            Textarea::make('ad_excluded_ous')
                                ->label(__('OUs to skip (optional)'))
                                ->placeholder('OU=Disabled,DC=corp,DC=example,DC=com')
                                ->rows(2)
                                ->helperText(__('One distinguished name per line. Computers in these OUs and below are not sent.')),
                        ]),
                    Step::make(__('Assets'))
                        ->description(__('What counts as active, and which computers become assets.'))
                        ->icon(Heroicon::OutlinedComputerDesktop)
                        ->schema([
                            TextInput::make('ad_active_threshold_days')
                                ->label(__('Active if seen within (days)'))
                                ->numeric()
                                ->minValue(OrganizationIntegrationSettings::ACTIVE_DIRECTORY_MIN_ACTIVE_DAYS)
                                ->maxValue(365)
                                ->required()
                                ->helperText(__('Uses the newest of last logon and machine password change. At least :days days, because Active Directory only updates the last logon time every 9 to 14 days.', ['days' => OrganizationIntegrationSettings::ACTIVE_DIRECTORY_MIN_ACTIVE_DAYS])),
                            TextInput::make('ad_skip_inactive_after_days')
                                ->label(__('Skip computers not seen for (days)'))
                                ->numeric()
                                ->minValue(0)
                                ->maxValue(OrganizationIntegrationSettings::ACTIVE_DIRECTORY_MAX_SKIP_INACTIVE_DAYS)
                                ->required()
                                ->helperText(__('Old computer objects nobody removed from AD are left out of HelixDesk entirely: no asset and no row in Device coverage. Computers already imported are removed from Device coverage on the next run. Use 0 to import every computer.')),
                            Toggle::make('ad_skip_disabled')
                                ->label(__('Skip disabled computers'))
                                ->helperText(__('Disabled computer accounts are left out of HelixDesk. Domain controllers are always imported.')),
                            Radio::make('ad_create_assets')
                                ->label(__('Create assets for'))
                                ->options([
                                    'active_only' => __('Active computers only (recommended)'),
                                    'all' => __('All computers that are not skipped'),
                                    'never' => __('None: only show them in Device coverage'),
                                ])
                                ->required()
                                ->helperText(__('Computers that already exist as assets (same device ID or the same computer name) are always linked, whatever you choose here.')),
                        ]),
                    Step::make(__('Enable'))
                        ->description(__('Accept data from the collector.'))
                        ->icon(Heroicon::OutlinedPower)
                        ->schema([
                            TextInput::make('ad_sync_interval_hours')
                                ->label(__('Collector runs every (hours)'))
                                ->numeric()
                                ->minValue(1)
                                ->maxValue(168)
                                ->required()
                                ->helperText(__('The collector reads this on every run. HelixDesk warns when it has not reported for three intervals.')),
                            Toggle::make('ad_enabled')
                                ->label(__('Enable Active Directory integration for this organization'))
                                ->helperText(__('While disabled, HelixDesk rejects data from the collector.')),
                        ]),
                ])
                    ->skippable(fn (Component $livewire): bool => $livewire instanceof EditOrganizationIntegration)
                    ->columnSpanFull(),
            ]);
    }
}
