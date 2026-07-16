<?php

namespace App\Support;

use App\Enums\Action1ApiRegion;
use App\Models\Organization;
use App\Models\OrganizationIntegration;
use App\Repositories\OrganizationIntegrationRepository;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

final class OrganizationIntegrationSettings
{
    public const TYPE_MICROSOFT_ENTRA = IntegrationType::MICROSOFT_ENTRA;

    public const TYPE_GOOGLE_WORKSPACE = IntegrationType::GOOGLE_WORKSPACE;

    public const TYPE_ACTION1 = IntegrationType::ACTION1;

    /**
     * @return list<string>
     */
    public static function registeredTypes(): array
    {
        return IntegrationType::registered();
    }

    /**
     * @return list<string>
     */
    public static function assetSyncTypes(): array
    {
        return [
            self::TYPE_MICROSOFT_ENTRA,
            self::TYPE_ACTION1,
        ];
    }

    /**
     * @return list<string>
     */
    public static function mailTransportTypes(): array
    {
        return IntegrationType::mailTransportTypes();
    }

    /**
     * @return list<array{id: string, type: string, config: array<string, mixed>}>
     */
    public static function listEntriesForOrganization(Organization $organization): array
    {
        return app(OrganizationIntegrationRepository::class)->listEntries($organization);
    }

    public static function findEntryForOrganization(Organization $organization, string $integrationId): ?array
    {
        return app(OrganizationIntegrationRepository::class)->findEntry($organization, $integrationId);
    }

    public static function typeLabel(string $type): string
    {
        return IntegrationType::label($type);
    }

    /**
     * @return array<string, string> integration type => label
     */
    public static function creatableTypeLabels(): array
    {
        $labels = [];

        foreach (self::registeredTypes() as $type) {
            $labels[$type] = self::typeLabel($type);
        }

        return $labels;
    }

    /**
     * @return list<EntraSyncTarget>
     */
    public static function collectEntraSyncTargets(): array
    {
        $targets = [];

        foreach (OrganizationIntegration::query()->withoutGlobalScopes()
            ->where('type', self::TYPE_MICROSOFT_ENTRA)
            ->orderBy('tenant_id')
            ->orderBy('organization_id')
            ->orderBy('id')
            ->cursor() as $integration) {
            $target = self::entraSyncTargetFromIntegration($integration);
            if ($target !== null) {
                $targets[] = $target;
            }
        }

        return $targets;
    }

    /**
     * @return list<Action1SyncTarget>
     */
    public static function collectAction1SyncTargets(): array
    {
        $targets = [];

        foreach (OrganizationIntegration::query()->withoutGlobalScopes()
            ->where('type', self::TYPE_ACTION1)
            ->orderBy('tenant_id')
            ->orderBy('organization_id')
            ->orderBy('id')
            ->cursor() as $integration) {
            $target = self::action1SyncTargetFromIntegration($integration);
            if ($target !== null) {
                $targets[] = $target;
            }
        }

        return $targets;
    }

    public static function hasAnyAssetSyncIntegrationDueForImport(): bool
    {
        return self::firstAssetSyncIntegrationDueForImport() !== null;
    }

    /**
     * @return array{type: string, target: EntraSyncTarget|Action1SyncTarget}|null
     */
    public static function firstAssetSyncIntegrationDueForImport(): ?array
    {
        $candidates = [];

        foreach (self::collectEntraSyncTargets() as $target) {
            if (self::microsoftEntraImportIsDue($target->config, $target->integrationId)) {
                $candidates[] = [
                    'type' => self::TYPE_MICROSOFT_ENTRA,
                    'target' => $target,
                    'due_at' => self::resolveLastSyncTimestamp($target->config, self::TYPE_MICROSOFT_ENTRA),
                ];
            }
        }

        foreach (self::collectAction1SyncTargets() as $target) {
            if (self::action1ImportIsDue($target->config, $target->integrationId)) {
                $candidates[] = [
                    'type' => self::TYPE_ACTION1,
                    'target' => $target,
                    'due_at' => self::resolveLastSyncTimestamp($target->config, self::TYPE_ACTION1),
                ];
            }
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, function (array $a, array $b): int {
            $aDue = $a['due_at'];
            $bDue = $b['due_at'];
            if ($aDue === null && $bDue === null) {
                return 0;
            }
            if ($aDue === null) {
                return -1;
            }
            if ($bDue === null) {
                return 1;
            }

            return $aDue <=> $bDue;
        });

        return [
            'type' => $candidates[0]['type'],
            'target' => $candidates[0]['target'],
        ];
    }

    public static function hasMicrosoftEntraIntegrationDueForImport(): bool
    {
        foreach (self::collectEntraSyncTargets() as $target) {
            if (self::microsoftEntraImportIsDue($target->config, $target->integrationId)) {
                return true;
            }
        }

        return false;
    }

    public static function firstMicrosoftEntraIntegrationDueForImport(): ?EntraSyncTarget
    {
        foreach (self::collectEntraSyncTargets() as $target) {
            if (self::microsoftEntraImportIsDue($target->config, $target->integrationId)) {
                return $target;
            }
        }

        return null;
    }

    public static function hasAction1IntegrationDueForImport(): bool
    {
        foreach (self::collectAction1SyncTargets() as $target) {
            if (self::action1ImportIsDue($target->config, $target->integrationId)) {
                return true;
            }
        }

        return false;
    }

    public static function firstAction1IntegrationDueForImport(): ?Action1SyncTarget
    {
        foreach (self::collectAction1SyncTargets() as $target) {
            if (self::action1ImportIsDue($target->config, $target->integrationId)) {
                return $target;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function microsoftEntraImportIsDue(array $config, ?string $integrationId = null): bool
    {
        if (! self::entraConfigReadyForSync($config)) {
            return false;
        }

        $hours = self::resolveImportIntervalHours(self::TYPE_MICROSOFT_ENTRA, $config, $integrationId);

        return self::lastSyncIsStale($config, self::TYPE_MICROSOFT_ENTRA, $hours);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function action1ImportIsDue(array $config, ?string $integrationId = null): bool
    {
        if (! self::action1ConfigReadyForSync($config)) {
            return false;
        }

        $hours = self::resolveImportIntervalHours(self::TYPE_ACTION1, $config, $integrationId);

        return self::lastSyncIsStale($config, self::TYPE_ACTION1, $hours);
    }

    /**
     * @param  array<string, mixed>  $configPatch
     */
    public static function patchMicrosoftEntraIntegrationConfig(Organization $organization, string $integrationId, array $configPatch): void
    {
        app(OrganizationIntegrationRepository::class)->patchIntegration(
            $organization,
            $integrationId,
            $configPatch,
        );
    }

    /**
     * @param  array<string, mixed>  $configPatch
     */
    public static function patchAction1IntegrationConfig(Organization $organization, string $integrationId, array $configPatch): void
    {
        app(OrganizationIntegrationRepository::class)->patchIntegration(
            $organization,
            $integrationId,
            $configPatch,
        );
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

        foreach (MicrosoftEntraIntegrationCatalog::directorySyncScopeKeys() as $key) {
            if ($scopes[$key] ?? false) {
                return null;
            }
        }

        return 'no_sync_scopes_enabled';
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function action1ConfigReadyForSync(array $config): bool
    {
        return self::action1ConfigSyncSkipReason($config) === null;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function action1ConfigSyncSkipReason(array $config): ?string
    {
        if (! ($config['enabled'] ?? false)) {
            return 'integration_disabled';
        }

        $organizationId = trim((string) ($config['action1_organization_id'] ?? ''));
        if ($organizationId === '') {
            return 'missing_action1_organization_id';
        }

        $clientId = trim((string) ($config['client_id'] ?? ''));
        $clientSecret = trim((string) ($config['client_secret'] ?? ''));
        $apiKey = trim((string) ($config['api_key'] ?? ''));
        if ($clientId === '') {
            return 'missing_client_id';
        }
        if ($clientSecret === '' && $apiKey === '') {
            return 'missing_client_secret_or_api_key';
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
    public static function entraFormHasCapability(array $data): bool
    {
        return self::entraFormHasSyncScope($data)
            || ($data['entra_scope_mail'] ?? false);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function action1FormHasSyncScope(array $data): bool
    {
        return ($data['action1_scope_endpoints'] ?? false)
            || ($data['action1_scope_software'] ?? false)
            || ($data['action1_scope_patches'] ?? false)
            || ($data['action1_scope_alerts'] ?? false);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function validateEntraFormDataForSave(array $data): void
    {
        if (! ($data['entra_enabled'] ?? false)) {
            return;
        }

        if (self::entraFormHasCapability($data)) {
            return;
        }

        throw ValidationException::withMessages([
            'entra_scope_users' => __('Select at least one capability when the integration is enabled.'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function validateAction1FormDataForSave(array $data): void
    {
        if (! ($data['action1_enabled'] ?? false)) {
            return;
        }

        if (self::action1FormHasSyncScope($data)) {
            return;
        }

        throw ValidationException::withMessages([
            'action1_scope_endpoints' => __('Select at least one capability to sync when the integration is enabled.'),
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
                'mail' => false,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function blankAction1FormFields(): array
    {
        return self::action1ConfigToFormFields([
            'enabled' => false,
            'client_id' => '',
            'client_secret' => '',
            'api_key' => '',
            'action1_organization_id' => '',
            'action1_organization_name' => '',
            'action1_group_id' => '',
            'api_base_url' => config('helixdesk_integrations.action1.default_api_base_url'),
            'scopes' => [
                'endpoints' => true,
                'software' => true,
                'patches' => true,
                'alerts' => false,
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
            'entra_scope_mail' => (bool) ($scopes['mail'] ?? false),
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public static function action1ConfigToFormFields(array $config): array
    {
        $scopes = is_array($config['scopes'] ?? null) ? $config['scopes'] : [];
        $apiBaseUrl = trim((string) ($config['api_base_url'] ?? ''));
        $region = Action1ApiRegion::Us->value;

        foreach (Action1ApiRegion::cases() as $case) {
            if ($case->apiBaseUrl() === $apiBaseUrl) {
                $region = $case->value;

                break;
            }
        }

        return [
            'action1_enabled' => (bool) ($config['enabled'] ?? false),
            'action1_region' => $region,
            'action1_api_base_url' => $apiBaseUrl !== '' ? $apiBaseUrl : config('helixdesk_integrations.action1.default_api_base_url'),
            'action1_client_id' => (string) ($config['client_id'] ?? ''),
            'action1_client_secret' => '',
            'action1_api_key' => '',
            'action1_organization_id' => (string) ($config['action1_organization_id'] ?? ''),
            'action1_organization_name' => (string) ($config['action1_organization_name'] ?? ''),
            'action1_group_id' => (string) ($config['action1_group_id'] ?? ''),
            'action1_scope_endpoints' => (bool) ($scopes['endpoints'] ?? true),
            'action1_scope_software' => (bool) ($scopes['software'] ?? true),
            'action1_scope_patches' => (bool) ($scopes['patches'] ?? true),
            'action1_scope_alerts' => (bool) ($scopes['alerts'] ?? false),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>|null  $previousConfig
     * @return array<string, mixed>
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
                'mail' => (bool) ($data['entra_scope_mail'] ?? false),
            ],
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
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public static function googleWorkspaceConfigToFormFields(array $config): array
    {
        return [
            'gws_customer_domain' => (string) ($config['customer_domain'] ?? ''),
            'gws_service_account_client_email' => (string) ($config['service_account_client_email'] ?? ''),
            'gws_service_account_private_key' => '',
            'gws_domain_wide_delegation_client_id' => (string) ($config['domain_wide_delegation_client_id'] ?? ''),
            'gws_enabled' => (bool) ($config['enabled'] ?? false),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>|null  $previousConfig
     */
    public static function validateGoogleWorkspaceFormDataForSave(array $data, ?array $previousConfig = null): void
    {
        $errors = [];

        if (trim((string) ($data['gws_customer_domain'] ?? '')) === '') {
            $errors['gws_customer_domain'] = __('The workspace domain is required.');
        }

        if (trim((string) ($data['gws_service_account_client_email'] ?? '')) === '') {
            $errors['gws_service_account_client_email'] = __('The service account email is required.');
        }

        $privateKey = trim((string) ($data['gws_service_account_private_key'] ?? ''));
        $hasPreviousKey = is_array($previousConfig)
            && trim((string) ($previousConfig['service_account_private_key'] ?? '')) !== '';
        if ($privateKey === '' && ! $hasPreviousKey) {
            $errors['gws_service_account_private_key'] = __('The service account private key is required.');
        }

        if (trim((string) ($data['gws_domain_wide_delegation_client_id'] ?? '')) === '') {
            $errors['gws_domain_wide_delegation_client_id'] = __('The domain-wide delegation client ID is required.');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
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
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>|null  $previousConfig
     * @return array<string, mixed>
     */
    public static function action1FormDataToConfig(array $data, ?array $previousConfig = null): array
    {
        $submittedSecret = trim((string) ($data['action1_client_secret'] ?? ''));
        $clientSecret = $submittedSecret;
        if ($clientSecret === '' && is_array($previousConfig)) {
            $previousSecret = $previousConfig['client_secret'] ?? null;
            if (is_string($previousSecret) && $previousSecret !== '') {
                $clientSecret = $previousSecret;
            }
        }

        $submittedApiKey = trim((string) ($data['action1_api_key'] ?? ''));
        $apiKey = $submittedApiKey;
        if ($apiKey === '' && is_array($previousConfig)) {
            $previousApiKey = $previousConfig['api_key'] ?? null;
            if (is_string($previousApiKey) && $previousApiKey !== '') {
                $apiKey = $previousApiKey;
            }
        }

        $region = Action1ApiRegion::tryFrom((string) ($data['action1_region'] ?? ''));
        $apiBaseUrl = trim((string) ($data['action1_api_base_url'] ?? ''));
        if ($region !== null) {
            $apiBaseUrl = $region->apiBaseUrl();
        }
        if ($apiBaseUrl === '') {
            $apiBaseUrl = config('helixdesk_integrations.action1.default_api_base_url');
        }

        return [
            'enabled' => (bool) ($data['action1_enabled'] ?? false),
            'client_id' => (string) ($data['action1_client_id'] ?? ''),
            'client_secret' => $clientSecret,
            'api_key' => $apiKey,
            'action1_organization_id' => (string) ($data['action1_organization_id'] ?? ''),
            'action1_organization_name' => (string) ($data['action1_organization_name'] ?? ''),
            'action1_group_id' => (string) ($data['action1_group_id'] ?? ''),
            'api_base_url' => $apiBaseUrl,
            'scopes' => [
                'endpoints' => (bool) ($data['action1_scope_endpoints'] ?? false),
                'software' => (bool) ($data['action1_scope_software'] ?? false),
                'patches' => (bool) ($data['action1_scope_patches'] ?? false),
                'alerts' => (bool) ($data['action1_scope_alerts'] ?? false),
            ],
        ];
    }

    private static function entraSyncTargetFromIntegration(OrganizationIntegration $integration): ?EntraSyncTarget
    {
        $tenantId = $integration->tenant_id;
        if ($tenantId === null) {
            return null;
        }

        $config = self::mergedConfigForIntegration($integration);
        if (! self::entraConfigReadyForSync($config)) {
            return null;
        }

        return new EntraSyncTarget(
            tenantId: (int) $tenantId,
            organizationId: (int) $integration->organization_id,
            integrationId: (string) $integration->getKey(),
            config: $config,
        );
    }

    private static function action1SyncTargetFromIntegration(OrganizationIntegration $integration): ?Action1SyncTarget
    {
        $tenantId = $integration->tenant_id;
        if ($tenantId === null) {
            return null;
        }

        $config = self::mergedConfigForIntegration($integration);
        if (! self::action1ConfigReadyForSync($config)) {
            return null;
        }

        $credentials = $integration->mergedCredentials();

        return new Action1SyncTarget(
            tenantId: (int) $tenantId,
            organizationId: (int) $integration->organization_id,
            integrationId: (string) $integration->getKey(),
            config: $config,
            credentials: $credentials,
            apiBaseUrl: $integration->api_base_url ?? ($config['api_base_url'] ?? null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function mergedConfigForIntegration(OrganizationIntegration $integration): array
    {
        $repository = app(OrganizationIntegrationRepository::class);
        $organization = Organization::query()->withoutGlobalScopes()->find($integration->organization_id);
        if ($organization === null) {
            return [];
        }

        $entry = $repository->findEntry($organization, (string) $integration->getKey());

        return is_array($entry['config'] ?? null) ? $entry['config'] : [];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function resolveImportIntervalHours(string $type, array $config, ?string $integrationId): int
    {
        if (isset($config['sync_interval_hours'])) {
            return max(1, (int) $config['sync_interval_hours']);
        }

        if ($integrationId !== null) {
            $integration = OrganizationIntegration::query()->withoutGlobalScopes()->find($integrationId);
            if ($integration !== null && $integration->sync_interval_hours !== null) {
                return max(1, (int) $integration->sync_interval_hours);
            }
        }

        $default = match ($type) {
            self::TYPE_ACTION1 => config('helixdesk_integrations.action1.import_interval_hours', 6),
            default => config('helixdesk_integrations.entra.import_interval_hours', 24),
        };

        return max(1, (int) $default);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function lastSyncIsStale(array $config, string $type, int $hours): bool
    {
        $raw = match ($type) {
            self::TYPE_MICROSOFT_ENTRA => $config['last_entra_sync_at'] ?? $config['last_sync_at'] ?? null,
            self::TYPE_ACTION1 => $config['last_sync_at'] ?? $config['last_action1_sync_at'] ?? null,
            default => $config['last_sync_at'] ?? null,
        };

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
     * @param  array<string, mixed>  $config
     */
    private static function resolveLastSyncTimestamp(array $config, string $type): ?Carbon
    {
        $raw = match ($type) {
            self::TYPE_MICROSOFT_ENTRA => $config['last_entra_sync_at'] ?? $config['last_sync_at'] ?? null,
            self::TYPE_ACTION1 => $config['last_sync_at'] ?? $config['last_action1_sync_at'] ?? null,
            default => $config['last_sync_at'] ?? null,
        };

        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        try {
            return Carbon::parse($raw);
        } catch (\Throwable) {
            return null;
        }
    }
}
