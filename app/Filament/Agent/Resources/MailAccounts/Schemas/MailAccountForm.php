<?php

namespace App\Filament\Agent\Resources\MailAccounts\Schemas;

use App\Enums\MailInboundProtocol;
use App\Enums\MailOutboundProtocol;
use App\Filament\Agent\Resources\Organizations\Schemas\OrganizationEntraIntegrationInlineSchema;
use App\Filament\Agent\Resources\Organizations\Schemas\OrganizationGoogleWorkspaceIntegrationInlineSchema;
use App\Models\MailAccount;
use App\Models\Organization;
use App\Support\MailProviderPresets;
use App\Support\MicrosoftEntraIntegrationCatalog;
use App\Support\OrganizationIntegrationOptions;
use App\Support\OrganizationIntegrationProvisioner;
use App\Support\OrganizationIntegrationSettings;
use Filament\Actions\Action;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use RuntimeException;

class MailAccountForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('General')
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('email_address')
                            ->email()
                            ->required()
                            ->maxLength(255),
                        Toggle::make('is_enabled')
                            ->default(true),
                        Toggle::make('supports_plus_addressing')
                            ->label('Supports plus-addressing')
                            ->helperText('Enable only if this mailbox receives mail addressed to local+tag@domain (e.g. support+a7Kx2@…). When on, outbound replies set Reply-To with the work item public id. Subject tokens still work either way.')
                            ->default(false),
                        Placeholder::make('last_polled_at_display')
                            ->label('Last polled')
                            ->content(fn (?MailAccount $record): string => $record?->last_polled_at?->toDateTimeString() ?? '—')
                            ->visible(fn (?MailAccount $record): bool => $record !== null),
                        Placeholder::make('last_poll_error_display')
                            ->label('Last poll error')
                            ->content(fn (?MailAccount $record): string => filled($record?->last_poll_error) ? (string) $record->last_poll_error : '—')
                            ->visible(fn (?MailAccount $record): bool => $record !== null && filled($record->last_poll_error)),
                    ]),
                Section::make('Protocols')
                    ->schema([
                        Select::make('protocol')
                            ->options([
                                MailInboundProtocol::Imap->value => 'IMAP',
                                MailInboundProtocol::Pop3->value => 'POP3',
                                MailInboundProtocol::MicrosoftGraph->value => 'Microsoft 365 (Graph)',
                                MailInboundProtocol::GoogleGmailApi->value => 'Google Workspace (API)',
                            ])
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (?string $state, Set $set): void {
                                if ($state === MailInboundProtocol::GoogleGmailApi->value) {
                                    $set('outbound_protocol', MailOutboundProtocol::GoogleGmailApi->value);
                                }

                                $set('integration_id', null);
                                $set('integration_type', null);
                            }),
                        Select::make('outbound_protocol')
                            ->options([
                                MailOutboundProtocol::Smtp->value => 'SMTP',
                                MailOutboundProtocol::MicrosoftGraph->value => 'Microsoft 365 (Graph)',
                                MailOutboundProtocol::GoogleGmailApi->value => 'Google Workspace (API)',
                            ])
                            ->required()
                            ->live()
                            ->visible(fn (Get $get): bool => ! self::usesGoogleGmailApi($get))
                            ->afterStateUpdated(function (?string $state, Set $set, Get $get): void {
                                if ($state === MailOutboundProtocol::GoogleGmailApi->value) {
                                    $set('protocol', MailInboundProtocol::GoogleGmailApi->value);
                                }

                                if ($state !== MailOutboundProtocol::MicrosoftGraph->value
                                    && $state !== MailOutboundProtocol::GoogleGmailApi->value) {
                                    return;
                                }

                                if ($get('protocol') !== MailInboundProtocol::GoogleGmailApi->value
                                    && $get('protocol') !== MailInboundProtocol::MicrosoftGraph->value) {
                                    $set('integration_id', null);
                                    $set('integration_type', null);
                                }
                            }),
                    ]),
                Section::make('Organization integration')
                    ->description('Select which organization integration authenticates API-based mail for this account.')
                    ->visible(fn (Get $get): bool => self::usesApiIntegration($get))
                    ->schema([
                        Callout::make(__('Microsoft Graph mail permissions'))
                            ->icon(Heroicon::OutlinedKey)
                            ->description(MicrosoftEntraIntegrationCatalog::mailPermissionsSummaryHtml())
                            ->visible(fn (Get $get): bool => self::usesMicrosoftGraph($get)),
                        Select::make('organization_id')
                            ->label('Organization')
                            ->options(fn (): array => OrganizationIntegrationOptions::organizationsForCurrentTenant())
                            ->searchable()
                            ->live()
                            ->required(fn (Get $get): bool => self::usesApiIntegration($get))
                            ->afterStateUpdated(function (Set $set): void {
                                $set('integration_id', null);
                                $set('integration_type', null);
                            }),
                        Select::make('integration_id')
                            ->label('Integration')
                            ->options(fn (Get $get): array => OrganizationIntegrationOptions::forOrganization(
                                $get('organization_id') !== null ? (int) $get('organization_id') : null,
                                self::allowedIntegrationTypes($get),
                            ))
                            ->searchable()
                            ->live()
                            ->required(fn (Get $get): bool => self::usesApiIntegration($get))
                            ->createOptionForm(fn (Get $get): array => self::integrationCreateFormSchema($get))
                            ->createOptionModalHeading(fn (Get $get): string => self::integrationCreateModalHeading($get))
                            ->createOptionAction(fn (Action $action, Get $get): Action => $action->visible(
                                fn (): bool => filled($get('organization_id')),
                            ))
                            ->createOptionUsing(function (array $data, Get $get): string {
                                $organizationId = $get('organization_id');
                                if ($organizationId === null) {
                                    throw new RuntimeException('Select an organization before creating an integration.');
                                }

                                $organization = Organization::query()->find((int) $organizationId);
                                if ($organization === null) {
                                    throw new RuntimeException('Organization not found.');
                                }

                                $allowedTypes = self::allowedIntegrationTypes($get);
                                if (count($allowedTypes) !== 1) {
                                    throw new RuntimeException('Unable to determine integration type for this mail account.');
                                }

                                if ($allowedTypes[0] === OrganizationIntegrationSettings::TYPE_MICROSOFT_ENTRA) {
                                    $data['entra_scope_mail'] = true;
                                }

                                $result = OrganizationIntegrationProvisioner::create(
                                    $organization,
                                    $allowedTypes[0],
                                    $data,
                                );

                                return $result['integration_id'];
                            })
                            ->afterStateUpdated(function (?string $state, Set $set, Get $get): void {
                                $set(
                                    'integration_type',
                                    OrganizationIntegrationOptions::resolveType(
                                        $get('organization_id') !== null ? (int) $get('organization_id') : null,
                                        $state,
                                    ),
                                );
                            }),
                        Hidden::make('integration_type'),
                        TextInput::make('connection_settings.mailbox_upn')
                            ->label('Mailbox address / UPN')
                            ->helperText('Email address or user principal name of the mailbox HelixDesk reads and sends as (often same as the account email address above).')
                            ->maxLength(255)
                            ->visible(fn (Get $get): bool => self::usesMicrosoftGraph($get)),
                        TextInput::make('connection_settings.mailbox_address')
                            ->label('Mailbox address')
                            ->helperText('Email address of the Google Workspace mailbox HelixDesk should impersonate via domain-wide delegation.')
                            ->maxLength(255)
                            ->visible(fn (Get $get): bool => self::usesGoogleGmailApi($get)),
                    ]),
                Section::make('IMAP / POP3 / SMTP connection')
                    ->visible(fn (Get $get): bool => self::showsImapSmtpConnection($get))
                    ->schema([
                        Select::make('connection_settings.preset')
                            ->label('Provider preset')
                            ->options(MailProviderPresets::labels())
                            ->default(MailProviderPresets::Generic)
                            ->live()
                            ->afterStateUpdated(function (?string $state, Set $set): void {
                                if ($state === null || $state === MailProviderPresets::Generic) {
                                    return;
                                }

                                foreach (MailProviderPresets::connectionDefaults($state) as $key => $value) {
                                    $set('connection_settings.'.$key, $value);
                                }
                            })
                            ->visible(fn (Get $get): bool => self::showsImapSmtpConnection($get)),
                        Grid::make(2)->schema([
                            TextInput::make('connection_settings.host')
                                ->label('Inbound host')
                                ->placeholder('imap.gmail.com')
                                ->maxLength(255)
                                ->visible(fn (Get $get): bool => $get('protocol') !== MailInboundProtocol::MicrosoftGraph->value
                                    && $get('protocol') !== MailInboundProtocol::GoogleGmailApi->value),
                            TextInput::make('connection_settings.port')
                                ->label('Inbound port')
                                ->numeric()
                                ->default(993)
                                ->visible(fn (Get $get): bool => $get('protocol') !== MailInboundProtocol::MicrosoftGraph->value
                                    && $get('protocol') !== MailInboundProtocol::GoogleGmailApi->value),
                            Select::make('connection_settings.encryption')
                                ->label('Inbound encryption')
                                ->options([
                                    'ssl' => 'SSL (port 993)',
                                    'tls' => 'TLS',
                                    '' => 'None',
                                ])
                                ->default('ssl')
                                ->visible(fn (Get $get): bool => $get('protocol') !== MailInboundProtocol::MicrosoftGraph->value
                                    && $get('protocol') !== MailInboundProtocol::GoogleGmailApi->value),
                            TextInput::make('connection_settings.username')
                                ->label('Username')
                                ->helperText('Use the full email address (e.g. you@gmail.com). Leave empty to use the account email address above.')
                                ->maxLength(255)
                                ->visible(fn (Get $get): bool => $get('protocol') !== MailInboundProtocol::MicrosoftGraph->value
                                    && $get('protocol') !== MailInboundProtocol::GoogleGmailApi->value
                                    || $get('outbound_protocol') === MailOutboundProtocol::Smtp->value),
                            TextInput::make('connection_settings.password')
                                ->label('Password / app password')
                                ->password()
                                ->revealable()
                                ->helperText(fn (Get $get): ?string => $get('connection_settings.preset') === MailProviderPresets::Gmail
                                    ? MailProviderPresets::gmailPasswordHelperText()
                                    : 'For Gmail and Google Workspace, use a Google App Password — not your normal login password.')
                                ->dehydrated(fn (?string $state): bool => filled($state))
                                ->visible(fn (Get $get): bool => $get('protocol') !== MailInboundProtocol::MicrosoftGraph->value
                                    && $get('protocol') !== MailInboundProtocol::GoogleGmailApi->value
                                    || $get('outbound_protocol') === MailOutboundProtocol::Smtp->value),
                            TextInput::make('connection_settings.smtp_host')
                                ->label('SMTP host')
                                ->placeholder('smtp.gmail.com')
                                ->maxLength(255)
                                ->visible(fn (Get $get): bool => $get('outbound_protocol') === MailOutboundProtocol::Smtp->value),
                            TextInput::make('connection_settings.smtp_port')
                                ->label('SMTP port')
                                ->numeric()
                                ->default(587)
                                ->visible(fn (Get $get): bool => $get('outbound_protocol') === MailOutboundProtocol::Smtp->value),
                            Select::make('connection_settings.smtp_encryption')
                                ->label('SMTP encryption')
                                ->options([
                                    'tls' => 'TLS (port 587)',
                                    'ssl' => 'SSL (port 465)',
                                    '' => 'None',
                                ])
                                ->default('tls')
                                ->visible(fn (Get $get): bool => $get('outbound_protocol') === MailOutboundProtocol::Smtp->value),
                        ]),
                    ]),
            ]);
    }

    /**
     * @return list<Field>
     */
    private static function integrationCreateFormSchema(Get $get): array
    {
        if (self::usesGoogleGmailApi($get)) {
            return OrganizationGoogleWorkspaceIntegrationInlineSchema::fields();
        }

        return OrganizationEntraIntegrationInlineSchema::fields();
    }

    private static function integrationCreateModalHeading(Get $get): string
    {
        if (self::usesGoogleGmailApi($get)) {
            return __('New Google Workspace integration');
        }

        return __('New Microsoft Entra integration');
    }

    /**
     * @return list<string>
     */
    private static function allowedIntegrationTypes(Get $get): array
    {
        if (self::usesGoogleGmailApi($get)) {
            return [OrganizationIntegrationSettings::TYPE_GOOGLE_WORKSPACE];
        }

        if (self::usesMicrosoftGraph($get)) {
            return [OrganizationIntegrationSettings::TYPE_MICROSOFT_ENTRA];
        }

        return [];
    }

    private static function usesApiIntegration(Get $get): bool
    {
        return self::usesMicrosoftGraph($get) || self::usesGoogleGmailApi($get);
    }

    private static function usesMicrosoftGraph(Get $get): bool
    {
        return $get('protocol') === MailInboundProtocol::MicrosoftGraph->value
            || $get('outbound_protocol') === MailOutboundProtocol::MicrosoftGraph->value;
    }

    private static function usesGoogleGmailApi(Get $get): bool
    {
        return $get('protocol') === MailInboundProtocol::GoogleGmailApi->value
            || $get('outbound_protocol') === MailOutboundProtocol::GoogleGmailApi->value;
    }

    private static function showsImapSmtpConnection(Get $get): bool
    {
        if (self::usesGoogleGmailApi($get)) {
            return false;
        }

        return $get('protocol') !== MailInboundProtocol::MicrosoftGraph->value
            || $get('outbound_protocol') === MailOutboundProtocol::Smtp->value;
    }
}
