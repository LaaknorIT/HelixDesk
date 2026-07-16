<?php

namespace App\Filament\Agent\Resources\Organizations\Schemas;

use App\Support\MicrosoftEntraIntegrationCatalog;
use App\Support\OrganizationEntraWizardDocumentation;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

class OrganizationEntraSettingsSchema
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Wizard::make([
                    Step::make(__('Overview'))
                        ->description(__('What this integration will do once background sync is enabled.'))
                        ->schema([
                            Callout::make(__('Microsoft Entra ID (Azure AD)'))
                                ->icon(Heroicon::OutlinedCloud)
                                ->description(OrganizationEntraWizardDocumentation::overviewHtml()),
                        ]),
                    Step::make(__('App registration'))
                        ->description(__('Create the Entra application that HelixDesk will use.'))
                        ->schema([
                            Callout::make(__('Steps in the Microsoft Entra admin center'))
                                ->icon(Heroicon::OutlinedClipboardDocumentList)
                                ->description(OrganizationEntraWizardDocumentation::appRegistrationHtml()),
                        ]),
                    Step::make(__('Capabilities'))
                        ->description(__('Choose directory sync and/or Microsoft 365 mail access for this organization.'))
                        ->schema([
                            Callout::make(__('Provisioning scope'))
                                ->description(OrganizationEntraWizardDocumentation::capabilitiesIntroHtml()),
                            Toggle::make('entra_scope_users')
                                ->label(fn (): string => MicrosoftEntraIntegrationCatalog::scopeDefinitions()['users']['label'])
                                ->helperText(fn (): string => MicrosoftEntraIntegrationCatalog::scopeDefinitions()['users']['description'])
                                ->default(false),
                            Toggle::make('entra_scope_departments')
                                ->label(fn (): string => MicrosoftEntraIntegrationCatalog::scopeDefinitions()['departments']['label'])
                                ->helperText(fn (): string => MicrosoftEntraIntegrationCatalog::scopeDefinitions()['departments']['description'])
                                ->default(false),
                            Toggle::make('entra_scope_computers')
                                ->label(fn (): string => MicrosoftEntraIntegrationCatalog::scopeDefinitions()['computers']['label'])
                                ->helperText(fn (): string => MicrosoftEntraIntegrationCatalog::scopeDefinitions()['computers']['description'])
                                ->default(false),
                            Toggle::make('entra_scope_security_work_items')
                                ->label(fn (): string => MicrosoftEntraIntegrationCatalog::scopeDefinitions()['security_work_items']['label'])
                                ->helperText(fn (): string => MicrosoftEntraIntegrationCatalog::scopeDefinitions()['security_work_items']['description'])
                                ->default(false),
                            Toggle::make('entra_scope_mail')
                                ->label(fn (): string => MicrosoftEntraIntegrationCatalog::scopeDefinitions()['mail']['label'])
                                ->helperText(fn (): string => MicrosoftEntraIntegrationCatalog::scopeDefinitions()['mail']['description'])
                                ->default(false)
                                ->live(),
                        ]),
                    Step::make(__('API permissions'))
                        ->description(__('Suggested Microsoft Graph application permissions based on your choices. Add these in Entra, then grant admin consent.'))
                        ->schema([
                            Callout::make(__('Permission checklist'))
                                ->description(fn (Get $get): HtmlString => MicrosoftEntraIntegrationCatalog::summaryHtml(
                                    MicrosoftEntraIntegrationCatalog::scopesFromGet($get)
                                )),
                        ]),
                    Step::make(__('Connect & enable'))
                        ->description(__('Identifiers, client secret, and master switch.'))
                        ->schema([
                            TextInput::make('entra_azure_tenant_id')
                                ->label(__('Directory (tenant) ID'))
                                ->maxLength(64)
                                ->default('')
                                ->helperText(__('UUID of the Entra tenant (Azure AD tenant).')),
                            TextInput::make('entra_application_client_id')
                                ->label(__('Application (client) ID'))
                                ->maxLength(64)
                                ->default('')
                                ->helperText(__('From the App registration overview blade.')),
                            TextInput::make('entra_client_secret')
                                ->label(__('Client secret'))
                                ->password()
                                ->revealable()
                                ->maxLength(512)
                                ->default('')
                                ->helperText(__('Paste the secret from Certificates & secrets. When editing, leave blank to keep the stored secret.')),
                            Toggle::make('entra_enabled')
                                ->label(__('Enable Microsoft Entra integration for this organization'))
                                ->helperText(__('When on, HelixDesk may run sync jobs for the selected capabilities (once workers are deployed).'))
                                ->default(false),
                        ]),
                ])
                    ->columnSpanFull(),
            ]);
    }
}
