<?php

namespace App\Repositories;

use App\Models\Organization;
use App\Models\OrganizationIntegration;
use App\Support\IntegrationType;
use App\Support\OrganizationIntegrationSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

final class OrganizationIntegrationRepository
{
    /**
     * @return list<array{id: string, type: string, config: array<string, mixed>}>
     */
    public function listEntries(Organization $organization): array
    {
        return OrganizationIntegration::query()
            ->withoutGlobalScopes()
            ->where('organization_id', $organization->getKey())
            ->orderBy('type')
            ->orderBy('id')
            ->get()
            ->map(fn (OrganizationIntegration $integration): array => $this->integrationToEntry($integration))
            ->all();
    }

    public function findEntry(Organization $organization, string $integrationId): ?array
    {
        $integration = OrganizationIntegration::query()
            ->withoutGlobalScopes()
            ->where('organization_id', $organization->getKey())
            ->where('id', $integrationId)
            ->first();

        if ($integration === null) {
            return null;
        }

        return $this->integrationToEntry($integration);
    }

    public function findModel(Organization $organization, string $integrationId): ?OrganizationIntegration
    {
        return OrganizationIntegration::query()
            ->withoutGlobalScopes()
            ->where('organization_id', $organization->getKey())
            ->where('id', $integrationId)
            ->first();
    }

    /**
     * @return list<OrganizationIntegration>
     */
    public function listModelsForOrganization(Organization $organization): array
    {
        return OrganizationIntegration::query()
            ->withoutGlobalScopes()
            ->where('organization_id', $organization->getKey())
            ->orderBy('type')
            ->orderBy('id')
            ->get()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $configPatch
     * @param  array<string, mixed>|null  $credentialsPatch
     */
    public function patchIntegration(
        Organization $organization,
        string $integrationId,
        array $configPatch = [],
        ?array $credentialsPatch = null,
    ): void {
        $integration = $this->findModel($organization, $integrationId);

        if ($integration === null) {
            return;
        }

        $this->patchIntegrationModel($integration, $configPatch, $credentialsPatch);
    }

    /**
     * @param  array<string, mixed>  $configPatch
     * @param  array<string, mixed>|null  $credentialsPatch
     */
    public function patchIntegrationModel(
        OrganizationIntegration $integration,
        array $configPatch = [],
        ?array $credentialsPatch = null,
    ): void {
        $config = $integration->mergedConfig();
        $mergedConfig = array_merge($config, $configPatch);

        $integration->config = self::stripSecretsFromConfig((string) $integration->type, $mergedConfig);

        if ($credentialsPatch !== null) {
            $credentials = $integration->mergedCredentials();
            $integration->credentials = array_merge($credentials, $credentialsPatch);
        }

        if (array_key_exists('last_entra_sync_at', $configPatch)) {
            $raw = $configPatch['last_entra_sync_at'];
            $integration->last_sync_at = is_string($raw) && trim($raw) !== ''
                ? Carbon::parse($raw)
                : null;
        }

        if (array_key_exists('last_entra_sync_error', $configPatch)) {
            $integration->last_sync_error = $configPatch['last_entra_sync_error'];
        }

        if (array_key_exists('last_sync_at', $configPatch)) {
            $raw = $configPatch['last_sync_at'];
            $integration->last_sync_at = is_string($raw) && trim($raw) !== ''
                ? Carbon::parse($raw)
                : ($raw instanceof Carbon ? $raw : $integration->last_sync_at);
        }

        if (array_key_exists('last_sync_error', $configPatch)) {
            $integration->last_sync_error = $configPatch['last_sync_error'];
        }

        if (array_key_exists('last_action1_sync_at', $configPatch)) {
            $raw = $configPatch['last_action1_sync_at'];
            $integration->last_sync_at = is_string($raw) && trim($raw) !== ''
                ? Carbon::parse($raw)
                : null;
        }

        if (array_key_exists('last_action1_sync_error', $configPatch)) {
            $integration->last_sync_error = $configPatch['last_action1_sync_error'];
        }

        if (array_key_exists('api_base_url', $configPatch)) {
            $integration->api_base_url = is_string($configPatch['api_base_url']) && trim($configPatch['api_base_url']) !== ''
                ? trim($configPatch['api_base_url'])
                : $integration->api_base_url;
        }

        $integration->scope_key = OrganizationIntegration::resolveScopeKey(
            (string) $integration->type,
            $mergedConfig,
        );
        $integration->save();
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $credentials
     */
    public function upsertFromLegacyEntry(
        Organization $organization,
        string $integrationId,
        string $type,
        array $config,
        array $credentials = [],
        ?string $apiBaseUrl = null,
    ): OrganizationIntegration {
        $scopeKey = OrganizationIntegration::resolveScopeKey($type, $config);
        $enabled = (bool) ($config['enabled'] ?? false);

        $lastSyncAt = null;
        $lastSyncError = null;
        if ($type === IntegrationType::MICROSOFT_ENTRA) {
            $rawSyncAt = $config['last_entra_sync_at'] ?? null;
            if (is_string($rawSyncAt) && trim($rawSyncAt) !== '') {
                $lastSyncAt = Carbon::parse($rawSyncAt);
            }
            $lastSyncError = $config['last_entra_sync_error'] ?? null;
        }

        if ($type === IntegrationType::ACTION1) {
            $rawSyncAt = $config['last_action1_sync_at'] ?? $config['last_sync_at'] ?? null;
            if (is_string($rawSyncAt) && trim($rawSyncAt) !== '') {
                $lastSyncAt = Carbon::parse($rawSyncAt);
            }
            $lastSyncError = $config['last_action1_sync_error'] ?? $config['last_sync_error'] ?? null;
        }

        $publicConfig = self::stripSecretsFromConfig($type, $config);

        return OrganizationIntegration::query()->updateOrCreate(
            [
                'id' => $integrationId,
            ],
            [
                'id' => $integrationId,
                'tenant_id' => $organization->tenant_id,
                'organization_id' => $organization->getKey(),
                'type' => $type,
                'enabled' => $enabled,
                'config' => $publicConfig,
                'credentials' => $credentials,
                'api_base_url' => $apiBaseUrl,
                'last_sync_at' => $lastSyncAt,
                'last_sync_error' => is_string($lastSyncError) ? $lastSyncError : null,
                'sync_interval_hours' => isset($config['sync_interval_hours'])
                    ? (int) $config['sync_interval_hours']
                    : null,
                'scope_key' => $scopeKey,
            ],
        );
    }

    /**
     * @return array{id: string, type: string, config: array<string, mixed>}
     */
    private function integrationToEntry(OrganizationIntegration $integration): array
    {
        $config = $integration->mergedConfig();
        $credentials = $integration->mergedCredentials();
        $mergedConfig = array_merge($config, self::credentialsIntoConfig($integration->type, $credentials));

        if ($integration->isMicrosoftEntra()) {
            if ($integration->last_sync_at !== null) {
                $mergedConfig['last_entra_sync_at'] = $integration->last_sync_at->toIso8601String();
            }
            if ($integration->last_sync_error !== null) {
                $mergedConfig['last_entra_sync_error'] = $integration->last_sync_error;
            }
        }

        if ($integration->isAction1()) {
            if ($integration->api_base_url !== null) {
                $mergedConfig['api_base_url'] = $integration->api_base_url;
            }
            if ($integration->last_sync_at !== null) {
                $mergedConfig['last_sync_at'] = $integration->last_sync_at->toIso8601String();
                $mergedConfig['last_action1_sync_at'] = $integration->last_sync_at->toIso8601String();
            }
            if ($integration->last_sync_error !== null) {
                $mergedConfig['last_sync_error'] = $integration->last_sync_error;
                $mergedConfig['last_action1_sync_error'] = $integration->last_sync_error;
            }
        }

        $mergedConfig['enabled'] = $integration->enabled;

        return [
            'id' => (string) $integration->getKey(),
            'type' => (string) $integration->type,
            'config' => $mergedConfig,
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public static function stripSecretsFromConfig(string $type, array $config): array
    {
        $out = $config;

        if ($type === IntegrationType::MICROSOFT_ENTRA) {
            unset($out['client_secret']);
        }

        if ($type === IntegrationType::GOOGLE_WORKSPACE) {
            unset($out['service_account_private_key']);
        }

        if ($type === IntegrationType::ACTION1) {
            unset($out['client_secret'], $out['api_key'], $out['client_id']);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array<string, mixed>
     */
    public static function credentialsIntoConfig(string $type, array $credentials): array
    {
        if ($type === IntegrationType::MICROSOFT_ENTRA) {
            return [
                'client_secret' => (string) ($credentials['client_secret'] ?? ''),
            ];
        }

        if ($type === IntegrationType::GOOGLE_WORKSPACE) {
            return [
                'service_account_private_key' => (string) ($credentials['service_account_private_key'] ?? ''),
            ];
        }

        if ($type === IntegrationType::ACTION1) {
            return array_filter([
                'client_id' => (string) ($credentials['client_id'] ?? ''),
                'client_secret' => (string) ($credentials['client_secret'] ?? ''),
                'api_key' => (string) ($credentials['api_key'] ?? ''),
            ], fn (string $value): bool => $value !== '');
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public static function extractCredentials(string $type, array $config): array
    {
        if ($type === IntegrationType::MICROSOFT_ENTRA) {
            return [
                'client_secret' => (string) ($config['client_secret'] ?? ''),
            ];
        }

        if ($type === IntegrationType::GOOGLE_WORKSPACE) {
            return [
                'service_account_private_key' => (string) ($config['service_account_private_key'] ?? ''),
            ];
        }

        if ($type === IntegrationType::ACTION1) {
            return array_filter([
                'client_id' => (string) ($config['client_id'] ?? ''),
                'client_secret' => (string) ($config['client_secret'] ?? ''),
                'api_key' => (string) ($config['api_key'] ?? ''),
            ], fn (string $value): bool => $value !== '');
        }

        return [];
    }

    public static function resolveIntegrationId(string $integrationId, string $type): string
    {
        if ($integrationId === '__legacy_microsoft_entra__') {
            return (string) Str::uuid();
        }

        return $integrationId;
    }

    public static function resolveApiBaseUrl(string $type, array $config): ?string
    {
        if ($type !== IntegrationType::ACTION1) {
            return null;
        }

        $explicit = trim((string) ($config['api_base_url'] ?? ''));
        if ($explicit !== '') {
            return $explicit;
        }

        return config('helixdesk_integrations.action1.default_api_base_url');
    }

    /**
     * @param  array<string, mixed>  $formData
     */
    public function createMicrosoftEntraFromForm(Organization $organization, array $formData): OrganizationIntegration
    {
        OrganizationIntegrationSettings::validateEntraFormDataForSave($formData);

        $config = OrganizationIntegrationSettings::entraFormDataToConfig($formData, null);

        return $this->createFromConfig(
            $organization,
            IntegrationType::MICROSOFT_ENTRA,
            $config,
        );
    }

    /**
     * @param  array<string, mixed>  $formData
     */
    public function updateMicrosoftEntraFromForm(
        Organization $organization,
        string $integrationId,
        array $formData,
    ): OrganizationIntegration {
        OrganizationIntegrationSettings::validateEntraFormDataForSave($formData);

        $integration = $this->findModel($organization, $integrationId);
        if ($integration === null) {
            abort(404);
        }

        $previousConfig = $integration->mergedConfig();
        $previousCredentials = $integration->mergedCredentials();
        $mergedPrevious = array_merge($previousConfig, self::credentialsIntoConfig($integration->type, $previousCredentials));
        $config = OrganizationIntegrationSettings::entraFormDataToConfig($formData, $mergedPrevious);

        return $this->updateFromConfig($integration, $config);
    }

    /**
     * @param  array<string, mixed>  $formData
     */
    public function createAction1FromForm(Organization $organization, array $formData): OrganizationIntegration
    {
        OrganizationIntegrationSettings::validateAction1FormDataForSave($formData);

        $config = OrganizationIntegrationSettings::action1FormDataToConfig($formData, null);

        return $this->createFromConfig(
            $organization,
            IntegrationType::ACTION1,
            $config,
        );
    }

    /**
     * @param  array<string, mixed>  $formData
     */
    public function updateAction1FromForm(
        Organization $organization,
        string $integrationId,
        array $formData,
    ): OrganizationIntegration {
        OrganizationIntegrationSettings::validateAction1FormDataForSave($formData);

        $integration = $this->findModel($organization, $integrationId);
        if ($integration === null) {
            abort(404);
        }

        $previousConfig = $integration->mergedConfig();
        $previousCredentials = $integration->mergedCredentials();
        $mergedPrevious = array_merge($previousConfig, self::credentialsIntoConfig($integration->type, $previousCredentials));
        $config = OrganizationIntegrationSettings::action1FormDataToConfig($formData, $mergedPrevious);

        return $this->updateFromConfig($integration, $config);
    }

    /**
     * @param  array<string, mixed>  $formData
     */
    public function createGoogleWorkspaceFromForm(Organization $organization, array $formData): OrganizationIntegration
    {
        OrganizationIntegrationSettings::validateGoogleWorkspaceFormDataForSave($formData);

        $config = OrganizationIntegrationSettings::googleWorkspaceFormDataToConfig($formData, null);

        return $this->createFromConfig(
            $organization,
            IntegrationType::GOOGLE_WORKSPACE,
            $config,
        );
    }

    /**
     * @param  array<string, mixed>  $formData
     */
    public function updateGoogleWorkspaceFromForm(
        Organization $organization,
        string $integrationId,
        array $formData,
    ): OrganizationIntegration {
        $integration = $this->findModel($organization, $integrationId);
        if ($integration === null) {
            abort(404);
        }

        $previousConfig = $integration->mergedConfig();
        $previousCredentials = $integration->mergedCredentials();
        $mergedPrevious = array_merge($previousConfig, self::credentialsIntoConfig($integration->type, $previousCredentials));

        OrganizationIntegrationSettings::validateGoogleWorkspaceFormDataForSave($formData, $mergedPrevious);

        $config = OrganizationIntegrationSettings::googleWorkspaceFormDataToConfig($formData, $mergedPrevious);

        return $this->updateFromConfig($integration, $config);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function createFromConfig(Organization $organization, string $type, array $config): OrganizationIntegration
    {
        $credentials = self::extractCredentials($type, $config);
        $publicConfig = self::stripSecretsFromConfig($type, $config);
        $apiBaseUrl = self::resolveApiBaseUrl($type, $config);

        return OrganizationIntegration::query()->create([
            'tenant_id' => $organization->tenant_id,
            'organization_id' => $organization->getKey(),
            'type' => $type,
            'enabled' => (bool) ($config['enabled'] ?? false),
            'config' => $publicConfig,
            'credentials' => $credentials,
            'api_base_url' => $apiBaseUrl,
            'scope_key' => OrganizationIntegration::resolveScopeKey($type, $config),
            'sync_interval_hours' => isset($config['sync_interval_hours'])
                ? (int) $config['sync_interval_hours']
                : null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function updateFromConfig(OrganizationIntegration $integration, array $config): OrganizationIntegration
    {
        $credentials = self::extractCredentials((string) $integration->type, $config);
        $publicConfig = self::stripSecretsFromConfig((string) $integration->type, $config);
        $apiBaseUrl = self::resolveApiBaseUrl((string) $integration->type, $config);

        $integration->enabled = (bool) ($config['enabled'] ?? false);
        $integration->config = $publicConfig;
        $integration->credentials = array_merge($integration->mergedCredentials(), $credentials);
        $integration->api_base_url = $apiBaseUrl;
        $integration->scope_key = OrganizationIntegration::resolveScopeKey((string) $integration->type, $config);
        $integration->save();

        return $integration->refresh();
    }
}
