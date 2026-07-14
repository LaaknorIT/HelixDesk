<?php

namespace App\Support;

use App\Models\Organization;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class OrganizationIntegrationSettings
{
    public const TYPE_MICROSOFT_ENTRA = 'microsoft_entra';

    public const TYPE_GOOGLE_WORKSPACE = 'google_workspace';

    public const LEGACY_MICROSOFT_ENTRA_ROW_ID = '__legacy_microsoft_entra__';

    /**
     * @return list<string>
     */
    public static function registeredTypes(): array
    {
        return [
            self::TYPE_MICROSOFT_ENTRA,
            self::TYPE_GOOGLE_WORKSPACE,
        ];
    }

    /**
     * @return list<string>
     */
    public static function mailTransportTypes(): array
    {
        return [
            self::TYPE_MICROSOFT_ENTRA,
            self::TYPE_GOOGLE_WORKSPACE,
        ];
    }

    /**
     * @return list<array{id: string, type: string, config: array<string, mixed>}>
     */
    public static function listEntries(?array $organizationalSettings): array
    {
        $raw = is_array($organizationalSettings) ? $organizationalSettings : [];

        $fromPersisted = self::persistedSettingsItems($raw);

        $hasMicrosoftEntraInPersisted = false;
        foreach ($fromPersisted as $item) {
            if (($item['type'] ?? '') === self::TYPE_MICROSOFT_ENTRA) {
                $hasMicrosoftEntraInPersisted = true;

                break;
            }
        }

        $entries = $fromPersisted;

        if (! $hasMicrosoftEntraInPersisted) {
            $legacy = data_get($raw, 'integrations.microsoft_entra');
            if (self::legacyMicrosoftEntraIsMeaningful(is_array($legacy) ? $legacy : [])) {
                $entries[] = [
                    'id' => self::LEGACY_MICROSOFT_ENTRA_ROW_ID,
                    'type' => self::TYPE_MICROSOFT_ENTRA,
                    'config' => self::normalizeLegacyEntraConfig($legacy),
                ];
            }
        }

        return $entries;
    }

    /**
     * @param  array<string, mixed>  $organizationalSettings
     * @return array{id: string, type: string, config: array<string, mixed>}|null
     */
    public static function findEntry(array $organizationalSettings, string $integrationId): ?array
    {
        foreach (self::listEntries($organizationalSettings) as $entry) {
            if (($entry['id'] ?? '') === $integrationId) {
                return $entry;
            }
        }

        return null;
    }

    public static function typeLabel(string $type): string
    {
        return match ($type) {
            self::TYPE_MICROSOFT_ENTRA => __('Microsoft Entra ID'),
            self::TYPE_GOOGLE_WORKSPACE => __('Google Workspace'),
            default => $type,
        };
    }

    /**
     * @return list<EntraSyncTarget>
     */
    public static function collectEntraSyncTargets(): array
    {
        $targets = [];
        foreach (Organization::query()->withoutGlobalScopes()->cursor() as $organization) {
            foreach (self::listEntries($organization->organizational_settings) as $entry) {
                if (($entry['type'] ?? '') !== self::TYPE_MICROSOFT_ENTRA) {
                    continue;
                }
                $config = is_array($entry['config'] ?? null) ? $entry['config'] : [];
                if (! self::entraConfigReadyForSync($config)) {
                    continue;
                }
                $tenantId = $organization->tenant_id;
                if ($tenantId === null) {
                    continue;
                }
                $targets[] = new EntraSyncTarget(
                    tenantId: (int) $tenantId,
                    organizationId: (int) $organization->getKey(),
                    integrationId: (string) $entry['id'],
                    config: $config,
                );
            }
        }

        usort(
            $targets,
            fn (EntraSyncTarget $a, EntraSyncTarget $b): int => [$a->tenantId, $a->organizationId, $a->integrationId] <=> [$b->tenantId, $b->organizationId, $b->integrationId]
        );

        return $targets;
    }

    public static function hasMicrosoftEntraIntegrationDueForImport(): bool
    {
        foreach (self::collectEntraSyncTargets() as $target) {
            if (self::microsoftEntraImportIsDue($target->config)) {
                return true;
            }
        }

        return false;
    }

    public static function firstMicrosoftEntraIntegrationDueForImport(): ?EntraSyncTarget
    {
        foreach (self::collectEntraSyncTargets() as $target) {
            if (self::microsoftEntraImportIsDue($target->config)) {
                return $target;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function microsoftEntraImportIsDue(array $config): bool
    {
        if (! self::entraConfigReadyForSync($config)) {
            return false;
        }

        $hours = max(1, (int) config('helixdesk.entra.import_interval_hours', 24));
        $raw = $config['last_entra_sync_at'] ?? null;
        if (! is_string($raw) || trim($raw) === '') {
            return true;
        }

        try {
            return Carbon::parse($raw)->addHours($hours)->isPast();
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * @param  array<string, mixed>  $configPatch  Merged into the integration config (e.g. last sync timestamps).
     */
    public static function patchMicrosoftEntraIntegrationConfig(Organization $organization, string $integrationId, array $configPatch): void
    {
        $raw = is_array($organization->organizational_settings) ? $organization->organizational_settings : [];

        if ($integrationId === self::LEGACY_MICROSOFT_ENTRA_ROW_ID) {
            $entra = data_get($raw, 'integrations.microsoft_entra');
            $raw['integrations']['microsoft_entra'] = array_merge(is_array($entra) ? $entra : [], $configPatch);
        } else {
            $items = $raw['settings'] ?? [];
            if (! is_array($items)) {
                $items = [];
            }
            foreach ($items as $k => $item) {
                if (! is_array($item)) {
                    continue;
                }
                if (($item['id'] ?? '') === $integrationId) {
                    $cfg = $item['config'] ?? [];
                    $items[$k]['config'] = array_merge(is_array($cfg) ? $cfg : [], $configPatch);
                    break;
                }
            }
            $raw['settings'] = $items;
        }

        $organization->organizational_settings = $raw;
        $organization->save();
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function entraConfigReadyForSync(array $config): bool
    {
        return self::entraConfigSyncSkipReason($config) === null;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function entraConfigSyncSkipReason(array $config): ?string
    {
        if (! ($config['enabled'] ?? false)) {
            return 'integration_disabled';
        }

        $tenant = trim((string) ($config['azure_tenant_id'] ?? ''));
        $client = trim((string) ($config['application_client_id'] ?? ''));
        $secret = trim((string) ($config['client_secret'] ?? ''));
        if ($tenant === '') {
            return 'missing_azure_tenant_id';
        }
        if ($client === '') {
            return 'missing_application_client_id';
        }
        if ($secret === '') {
            return 'missing_client_secret';
        }

        $scopes = $config['scopes'] ?? [];
        if (! is_array($scopes)) {
            return 'invalid_scopes';
        }

        foreach ($scopes as $on) {
            if ($on) {
                return null;
            }
        }

        return 'no_sync_scopes_enabled';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function entraFormHasSyncScope(array $data): bool
    {
        return ($data['entra_scope_users'] ?? false)
            || ($data['entra_scope_departments'] ?? false)
            || ($data['entra_scope_computers'] ?? false)
            || ($data['entra_scope_security_work_items'] ?? false);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function validateEntraFormDataForSave(array $data): void
    {
        if (! ($data['entra_enabled'] ?? false)) {
            return;
        }

        if (self::entraFormHasSyncScope($data)) {
            return;
        }

        throw ValidationException::withMessages([
            'entra_scope_users' => __('Select at least one capability to sync when the integration is enabled.'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function blankEntraFormFields(): array
    {
        return self::entraConfigToFormFields([
            'enabled' => false,
            'azure_tenant_id' => '',
            'application_client_id' => '',
            'client_secret' => '',
            'scopes' => [
                'users' => false,
                'departments' => false,
                'computers' => false,
                'security_work_items' => false,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public static function entraConfigToFormFields(array $config): array
    {
        $scopes = is_array($config['scopes'] ?? null) ? $config['scopes'] : [];

        return [
            'entra_enabled' => (bool) ($config['enabled'] ?? false),
            'entra_azure_tenant_id' => (string) ($config['azure_tenant_id'] ?? ''),
            'entra_application_client_id' => (string) ($config['application_client_id'] ?? ''),
            'entra_client_secret' => '',
            'entra_scope_users' => (bool) ($scopes['users'] ?? false),
            'entra_scope_departments' => (bool) ($scopes['departments'] ?? false),
            'entra_scope_computers' => (bool) ($scopes['computers'] ?? false),
            'entra_scope_security_work_items' => (bool) ($scopes['security_work_items'] ?? false),
        ];
    }

    /**
     * @param  array<string, mixed>  $data  Form state including entra_* keys
     * @return array<string, mixed>
     */
    /**
     * @param  array<string, mixed>|null  $previousConfig  When the client secret field is left blank on edit, the previous value is kept.
     */
    public static function entraFormDataToConfig(array $data, ?array $previousConfig = null): array
    {
        $submittedSecret = trim((string) ($data['entra_client_secret'] ?? ''));
        $clientSecret = $submittedSecret;
        if ($clientSecret === '' && is_array($previousConfig)) {
            $previousSecret = $previousConfig['client_secret'] ?? null;
            if (is_string($previousSecret) && $previousSecret !== '') {
                $clientSecret = $previousSecret;
            }
        }

        return [
            'enabled' => (bool) ($data['entra_enabled'] ?? false),
            'azure_tenant_id' => (string) ($data['entra_azure_tenant_id'] ?? ''),
            'application_client_id' => (string) ($data['entra_application_client_id'] ?? ''),
            'client_secret' => $clientSecret,
            'scopes' => [
                'users' => (bool) ($data['entra_scope_users'] ?? false),
                'departments' => (bool) ($data['entra_scope_departments'] ?? false),
                'computers' => (bool) ($data['entra_scope_computers'] ?? false),
                'security_work_items' => (bool) ($data['entra_scope_security_work_items'] ?? false),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $formData
     * @return array<string, mixed>
     */
    public static function appendMicrosoftEntraIntegration(?array $organizationalSettings, array $formData): array
    {
        $created = self::createMicrosoftEntraIntegration($organizationalSettings, $formData);

        return $created['organizational_settings'];
    }

    /**
     * @param  array<string, mixed>  $formData
     * @return array{integration_id: string, organizational_settings: array<string, mixed>}
     */
    public static function createMicrosoftEntraIntegration(?array $organizationalSettings, array $formData): array
    {
        $out = is_array($organizationalSettings) ? $organizationalSettings : [];
        $items = self::persistedSettingsItems($out);
        $items = self::ensureLegacyMicrosoftEntraMigratedIntoItems($out, $items);
        $integrationId = (string) Str::uuid();
        $items[] = [
            'id' => $integrationId,
            'type' => self::TYPE_MICROSOFT_ENTRA,
            'config' => self::entraFormDataToConfig($formData, null),
        ];
        $out = self::stripLegacyMicrosoftEntraIntegration($out);
        $out['settings'] = array_values($items);

        return [
            'integration_id' => $integrationId,
            'organizational_settings' => $out,
        ];
    }

    /**
     * @param  array<string, mixed>  $formData
     * @return array{integration_id: string, organizational_settings: array<string, mixed>}
     */
    public static function createGoogleWorkspaceIntegration(?array $organizationalSettings, array $formData): array
    {
        $out = is_array($organizationalSettings) ? $organizationalSettings : [];
        $items = self::persistedSettingsItems($out);
        $integrationId = (string) Str::uuid();
        $items[] = [
            'id' => $integrationId,
            'type' => self::TYPE_GOOGLE_WORKSPACE,
            'config' => self::googleWorkspaceFormDataToConfig($formData, null),
        ];
        $out['settings'] = array_values($items);

        return [
            'integration_id' => $integrationId,
            'organizational_settings' => $out,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function blankGoogleWorkspaceFormFields(): array
    {
        return [
            'gws_customer_domain' => '',
            'gws_service_account_client_email' => '',
            'gws_service_account_private_key' => '',
            'gws_domain_wide_delegation_client_id' => '',
            'gws_enabled' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function googleWorkspaceFormDataToConfig(array $data, ?array $previousConfig = null): array
    {
        $submittedKey = trim((string) ($data['gws_service_account_private_key'] ?? ''));
        $privateKey = $submittedKey;
        if ($privateKey === '' && is_array($previousConfig)) {
            $previousPrivateKey = $previousConfig['service_account_private_key'] ?? null;
            if (is_string($previousPrivateKey) && $previousPrivateKey !== '') {
                $privateKey = $previousPrivateKey;
            }
        }

        return [
            'enabled' => (bool) ($data['gws_enabled'] ?? false),
            'customer_domain' => (string) ($data['gws_customer_domain'] ?? ''),
            'service_account_client_email' => (string) ($data['gws_service_account_client_email'] ?? ''),
            'service_account_private_key' => $privateKey,
            'domain_wide_delegation_client_id' => (string) ($data['gws_domain_wide_delegation_client_id'] ?? ''),
            'delegated_scopes' => [
                'https://www.googleapis.com/auth/gmail.readonly',
                'https://www.googleapis.com/auth/gmail.send',
                'https://www.googleapis.com/auth/gmail.modify',
            ],
            'setup_verified_at' => is_array($previousConfig) ? ($previousConfig['setup_verified_at'] ?? null) : null,
            'last_mail_sync_at' => is_array($previousConfig) ? ($previousConfig['last_mail_sync_at'] ?? null) : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $formData
     * @return array<string, mixed>
     */
    public static function replaceMicrosoftEntraIntegration(?array $organizationalSettings, string $integrationId, array $formData): array
    {
        $out = is_array($organizationalSettings) ? $organizationalSettings : [];
        $items = self::persistedSettingsItems($out);
        $previousConfig = self::resolvePreviousEntraConfigForReplace($out, $integrationId);
        $config = self::entraFormDataToConfig($formData, $previousConfig);

        if ($integrationId === self::LEGACY_MICROSOFT_ENTRA_ROW_ID) {
            $items[] = [
                'id' => (string) Str::uuid(),
                'type' => self::TYPE_MICROSOFT_ENTRA,
                'config' => $config,
            ];
        } else {
            $found = false;
            foreach ($items as $index => $item) {
                if (($item['id'] ?? '') === $integrationId) {
                    $items[$index]['config'] = $config;
                    $found = true;

                    break;
                }
            }
            if (! $found) {
                abort(404);
            }
        }

        $out = self::stripLegacyMicrosoftEntraIntegration($out);
        $out['settings'] = array_values($items);

        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function resolvePreviousEntraConfigForReplace(array $out, string $integrationId): ?array
    {
        if ($integrationId === self::LEGACY_MICROSOFT_ENTRA_ROW_ID) {
            $legacy = data_get($out, 'integrations.microsoft_entra');

            return is_array($legacy) ? self::normalizeLegacyEntraConfig($legacy) : null;
        }

        foreach (self::persistedSettingsItems($out) as $item) {
            if (($item['id'] ?? '') === $integrationId && ($item['type'] ?? '') === self::TYPE_MICROSOFT_ENTRA) {
                return $item['config'];
            }
        }

        return null;
    }

    /**
     * When only `integrations.microsoft_entra` exists, migrating it into `settings` before appending
     * avoids dropping the prior configuration.
     *
     * @param  list<array{id: string, type: string, config: array<string, mixed>}>  $items
     * @return list<array{id: string, type: string, config: array<string, mixed>}>
     */
    private static function ensureLegacyMicrosoftEntraMigratedIntoItems(array $raw, array $items): array
    {
        foreach ($items as $item) {
            if (($item['type'] ?? '') === self::TYPE_MICROSOFT_ENTRA) {
                return $items;
            }
        }

        $legacy = data_get($raw, 'integrations.microsoft_entra');
        if (! self::legacyMicrosoftEntraIsMeaningful(is_array($legacy) ? $legacy : [])) {
            return $items;
        }

        $items[] = [
            'id' => (string) Str::uuid(),
            'type' => self::TYPE_MICROSOFT_ENTRA,
            'config' => self::normalizeLegacyEntraConfig($legacy),
        ];

        return $items;
    }

    /**
     * @return list<array{id: string, type: string, config: array<string, mixed>}>
     */
    private static function persistedSettingsItems(array $raw): array
    {
        $settings = $raw['settings'] ?? null;
        if (! is_array($settings)) {
            return [];
        }

        $items = [];
        foreach ($settings as $item) {
            if (! is_array($item)) {
                continue;
            }
            $id = $item['id'] ?? null;
            $type = $item['type'] ?? null;
            if (! is_string($id) || $id === '' || ! is_string($type) || $type === '') {
                continue;
            }
            $config = $item['config'] ?? [];
            $items[] = [
                'id' => $id,
                'type' => $type,
                'config' => is_array($config) ? $config : [],
            ];
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $legacy
     */
    private static function legacyMicrosoftEntraIsMeaningful(array $legacy): bool
    {
        if ($legacy === []) {
            return false;
        }

        if (! empty($legacy['azure_tenant_id']) || ! empty($legacy['application_client_id'])) {
            return true;
        }

        if (! empty($legacy['client_secret']) || ! empty($legacy['application_client_secret'])) {
            return true;
        }

        if (! empty($legacy['enabled'])) {
            return true;
        }

        $scopes = $legacy['scopes'] ?? [];
        if (! is_array($scopes)) {
            return false;
        }

        foreach ($scopes as $enabled) {
            if ($enabled) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $legacy
     * @return array<string, mixed>
     */
    private static function normalizeLegacyEntraConfig(array $legacy): array
    {
        $scopes = is_array($legacy['scopes'] ?? null) ? $legacy['scopes'] : [];

        $secret = (string) ($legacy['client_secret'] ?? $legacy['application_client_secret'] ?? '');

        return [
            'enabled' => (bool) ($legacy['enabled'] ?? false),
            'azure_tenant_id' => (string) ($legacy['azure_tenant_id'] ?? ''),
            'application_client_id' => (string) ($legacy['application_client_id'] ?? ''),
            'client_secret' => $secret,
            'scopes' => [
                'users' => (bool) ($scopes['users'] ?? false),
                'departments' => (bool) ($scopes['departments'] ?? false),
                'computers' => (bool) ($scopes['computers'] ?? false),
                'security_work_items' => (bool) ($scopes['security_work_items'] ?? false),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $out
     * @return array<string, mixed>
     */
    private static function stripLegacyMicrosoftEntraIntegration(array $out): array
    {
        if (isset($out['integrations']['microsoft_entra'])) {
            unset($out['integrations']['microsoft_entra']);
        }
        if (isset($out['integrations']) && $out['integrations'] === []) {
            unset($out['integrations']);
        }

        return $out;
    }
}
