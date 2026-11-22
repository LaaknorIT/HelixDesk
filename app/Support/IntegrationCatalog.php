<?php

namespace App\Support;

use App\Enums\DeviceSourceRole;
use Filament\Support\Icons\Heroicon;

/**
 * Describes every integration HelixDesk can connect to, for the integration gallery and health checks.
 * Adding a new integration type means adding one entry here plus its settings schema and sync service.
 */
final class IntegrationCatalog
{
    public const CATEGORY_IDENTITY = 'identity';

    public const CATEGORY_ENDPOINT_MANAGEMENT = 'endpoint_management';

    public const CATEGORY_EMAIL = 'email';

    public const CATEGORY_SECURITY = 'security';

    /**
     * @return array<string, string> category key => label
     */
    public static function categories(): array
    {
        return [
            self::CATEGORY_IDENTITY => __('Identity & directory'),
            self::CATEGORY_ENDPOINT_MANAGEMENT => __('Endpoint management & patching'),
            self::CATEGORY_EMAIL => __('Email'),
            self::CATEGORY_SECURITY => __('Security'),
        ];
    }

    /**
     * @return array<string, array{
     *     type: string,
     *     name: string,
     *     vendor: string,
     *     summary: string,
     *     categories: list<string>,
     *     icon: Heroicon,
     *     setup_minutes: int,
     *     requirements: list<string>,
     *     capabilities: array<string, string>,
     *     docs_url: ?string,
     *     device_sources: array<string, array{label: string, roles: list<DeviceSourceRole>, scope: string}>,
     * }>
     */
    public static function definitions(): array
    {
        $entraScopes = MicrosoftEntraIntegrationCatalog::scopeDefinitions();

        return [
            IntegrationType::MICROSOFT_ENTRA => [
                'type' => IntegrationType::MICROSOFT_ENTRA,
                'name' => __('Microsoft Entra ID'),
                'vendor' => __('Microsoft'),
                'summary' => __('Sync users, departments and Intune devices, ingest Defender security incidents and use Microsoft 365 mailboxes.'),
                'categories' => [self::CATEGORY_IDENTITY, self::CATEGORY_ENDPOINT_MANAGEMENT, self::CATEGORY_SECURITY, self::CATEGORY_EMAIL],
                'icon' => Heroicon::OutlinedCloud,
                'setup_minutes' => 10,
                'requirements' => [
                    __('Global administrator or Application administrator in the Microsoft tenant'),
                    __('Permission to grant admin consent for Microsoft Graph application permissions'),
                ],
                'capabilities' => [
                    'users' => __($entraScopes['users']['label']),
                    'departments' => __($entraScopes['departments']['label']),
                    'computers' => __($entraScopes['computers']['label']),
                    'security_work_items' => __($entraScopes['security_work_items']['label']),
                    'mail' => __($entraScopes['mail']['label']),
                ],
                'docs_url' => 'https://learn.microsoft.com/entra/identity-platform/quickstart-register-app',
                'device_sources' => [
                    DeviceSourceKey::MICROSOFT_INTUNE => [
                        'label' => __('Intune'),
                        'roles' => [DeviceSourceRole::DeviceManagement],
                        'scope' => 'computers',
                    ],
                ],
            ],
            IntegrationType::ACTION1 => [
                'type' => IntegrationType::ACTION1,
                'name' => __('Action1'),
                'vendor' => __('Action1'),
                'summary' => __('Import endpoints, installed software and missing updates, and poll Action1 alerts.'),
                'categories' => [self::CATEGORY_ENDPOINT_MANAGEMENT],
                'icon' => Heroicon::OutlinedComputerDesktop,
                'setup_minutes' => 5,
                'requirements' => [
                    __('An Action1 API credential (client ID and secret) with read access to the organization'),
                ],
                'capabilities' => [
                    'endpoints' => __('Endpoints / inventory'),
                    'software' => __('Installed software'),
                    'patches' => __('Missing updates / patch status'),
                    'alerts' => __('Alerts (audit events poll)'),
                ],
                'docs_url' => 'https://www.action1.com/api-documentation/',
                'device_sources' => [
                    DeviceSourceKey::ACTION1 => [
                        'label' => __('Action1'),
                        'roles' => [DeviceSourceRole::RemoteManagement],
                        'scope' => 'endpoints',
                    ],
                ],
            ],
            IntegrationType::ACTIVE_DIRECTORY => [
                'type' => IntegrationType::ACTIVE_DIRECTORY,
                'name' => __('Active Directory (on-premises)'),
                'vendor' => __('Microsoft'),
                'summary' => __('A small PowerShell collector on the customer network sends computer objects from Active Directory to HelixDesk over HTTPS. No VPN or inbound firewall rules.'),
                'categories' => [self::CATEGORY_IDENTITY],
                'icon' => Heroicon::OutlinedServerStack,
                'setup_minutes' => 15,
                'requirements' => [
                    __('A domain-joined Windows machine that can reach HelixDesk over HTTPS'),
                    __('A domain account (or gMSA) that can read computer objects; a normal domain user is enough'),
                ],
                'capabilities' => [
                    'computers' => __('Computers with last logon, operating system and OU'),
                ],
                'docs_url' => null,
                'device_sources' => [
                    DeviceSourceKey::ACTIVE_DIRECTORY => [
                        'label' => __('Active Directory'),
                        'roles' => [DeviceSourceRole::Directory],
                        'scope' => 'computers',
                    ],
                ],
            ],
            IntegrationType::GOOGLE_WORKSPACE => [
                'type' => IntegrationType::GOOGLE_WORKSPACE,
                'name' => __('Google Workspace'),
                'vendor' => __('Google'),
                'summary' => __('Receive and send ticket email through Gmail using a service account with domain-wide delegation.'),
                'categories' => [self::CATEGORY_EMAIL],
                'icon' => Heroicon::OutlinedEnvelope,
                'setup_minutes' => 15,
                'requirements' => [
                    __('A Google Cloud project with a service account'),
                    __('Super administrator access to configure domain-wide delegation'),
                ],
                'capabilities' => [
                    'mail' => __('Mail (Gmail API)'),
                ],
                'docs_url' => 'https://developers.google.com/workspace/guides/create-credentials#service-account',
                'device_sources' => [],
            ],
        ];
    }

    /**
     * @return array{type: string, name: string, vendor: string, summary: string, categories: list<string>, icon: Heroicon, setup_minutes: int, requirements: list<string>, capabilities: array<string, string>, docs_url: ?string, device_sources: array<string, array{label: string, roles: list<DeviceSourceRole>, scope: string}>}|null
     */
    public static function find(string $type): ?array
    {
        return self::definitions()[$type] ?? null;
    }

    public static function capabilityLabel(string $type, string $capability): string
    {
        return self::find($type)['capabilities'][$capability] ?? $capability;
    }

    /**
     * Definitions matching a free-text search and an optional category.
     *
     * @return list<array<string, mixed>>
     */
    public static function search(?string $query = null, ?string $category = null): array
    {
        $needle = mb_strtolower(trim((string) $query));
        $matches = [];

        foreach (self::definitions() as $definition) {
            if ($category !== null && $category !== '' && ! in_array($category, $definition['categories'], true)) {
                continue;
            }

            if ($needle !== '') {
                $haystack = mb_strtolower(implode(' ', [
                    $definition['name'],
                    $definition['vendor'],
                    $definition['summary'],
                    implode(' ', $definition['capabilities']),
                ]));

                if (! str_contains($haystack, $needle)) {
                    continue;
                }
            }

            $matches[] = $definition;
        }

        return $matches;
    }
}
