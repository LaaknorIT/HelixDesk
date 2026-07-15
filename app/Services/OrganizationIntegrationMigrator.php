<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\OrganizationIntegration;
use App\Repositories\OrganizationIntegrationRepository;
use App\Support\IntegrationType;

final class OrganizationIntegrationMigrator
{
    private const LEGACY_MICROSOFT_ENTRA_ROW_ID = '__legacy_microsoft_entra__';

    public function __construct(
        private readonly OrganizationIntegrationRepository $repository,
    ) {}

    /**
     * @param  callable(string): void|null  $logger
     */
    public function migrateAll(?callable $logger = null): int
    {
        $migrated = 0;

        Organization::query()->withoutGlobalScopes()->orderBy('id')->chunkById(100, function ($organizations) use ($logger, &$migrated): void {
            foreach ($organizations as $organization) {
                $migrated += $this->migrateOrganization($organization, $logger);
            }
        });

        return $migrated;
    }

    public function stripAllLegacyIntegrationJson(): void
    {
        Organization::query()->withoutGlobalScopes()->orderBy('id')->chunkById(100, function ($organizations): void {
            foreach ($organizations as $organization) {
                $entries = $this->listEntriesFromJson(
                    is_array($organization->organizational_settings) ? $organization->organizational_settings : [],
                );

                if ($entries === []) {
                    continue;
                }

                $this->stripMigratedEntriesFromOrganizationJson($organization, $entries);
            }
        });
    }

    /**
     * @param  callable(string): void|null  $logger
     */
    public function migrateOrganization(Organization $organization, ?callable $logger = null): int
    {
        $entries = $this->listEntriesFromJson(
            is_array($organization->organizational_settings) ? $organization->organizational_settings : [],
        );

        if ($entries === []) {
            return 0;
        }

        $migrated = 0;

        foreach ($entries as $entry) {
            $type = (string) ($entry['type'] ?? '');
            $config = is_array($entry['config'] ?? null) ? $entry['config'] : [];

            if ($type === '' || ! in_array($type, IntegrationType::registered(), true)) {
                if ($logger !== null) {
                    $logger("Skipping unknown integration type for organization {$organization->id}.");
                }

                continue;
            }

            $integrationId = OrganizationIntegrationRepository::resolveIntegrationId(
                (string) ($entry['id'] ?? ''),
                $type,
            );

            if ($this->alreadyMigrated($organization, $integrationId, $type, $config)) {
                if ($logger !== null) {
                    $logger("Skipping already migrated {$type} integration for organization {$organization->id}.");
                }

                continue;
            }

            $credentials = OrganizationIntegrationRepository::extractCredentials($type, $config);
            $apiBaseUrl = OrganizationIntegrationRepository::resolveApiBaseUrl($type, $config);

            $this->repository->upsertFromLegacyEntry(
                $organization,
                $integrationId,
                $type,
                $config,
                $credentials,
                $apiBaseUrl,
            );

            $migrated++;
            if ($logger !== null) {
                $logger("Migrated {$type} integration {$integrationId} for organization {$organization->id}.");
            }
        }

        return $migrated;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function alreadyMigrated(
        Organization $organization,
        string $integrationId,
        string $type,
        array $config,
    ): bool {
        if (OrganizationIntegration::query()->withoutGlobalScopes()->whereKey($integrationId)->exists()) {
            return true;
        }

        $scopeKey = OrganizationIntegration::resolveScopeKey($type, $config);

        return OrganizationIntegration::query()
            ->withoutGlobalScopes()
            ->where('organization_id', $organization->getKey())
            ->where('type', $type)
            ->where('scope_key', $scopeKey)
            ->exists();
    }

    /**
     * @param  list<array{id: string, type: string, config: array<string, mixed>}>  $entries
     */
    public function stripMigratedEntriesFromOrganizationJson(Organization $organization, array $entries): void
    {
        $raw = is_array($organization->organizational_settings) ? $organization->organizational_settings : [];
        $migratedIds = collect($entries)->pluck('id')->filter()->all();

        $items = $raw['settings'] ?? [];
        if (is_array($items)) {
            $raw['settings'] = array_values(array_filter(
                $items,
                fn ($item): bool => ! is_array($item) || ! in_array($item['id'] ?? null, $migratedIds, true),
            ));
        }

        if (isset($raw['integrations']['microsoft_entra'])) {
            unset($raw['integrations']['microsoft_entra']);
        }
        if (isset($raw['integrations']) && $raw['integrations'] === []) {
            unset($raw['integrations']);
        }

        if (isset($raw['settings']) && $raw['settings'] === []) {
            unset($raw['settings']);
        }

        $organization->organizational_settings = $raw;
        $organization->save();
    }

    /**
     * @return list<array{id: string, type: string, config: array<string, mixed>}>
     */
    private function listEntriesFromJson(?array $organizationalSettings): array
    {
        $raw = is_array($organizationalSettings) ? $organizationalSettings : [];

        $fromPersisted = $this->persistedSettingsItems($raw);

        $hasMicrosoftEntraInPersisted = false;
        foreach ($fromPersisted as $item) {
            if (($item['type'] ?? '') === IntegrationType::MICROSOFT_ENTRA) {
                $hasMicrosoftEntraInPersisted = true;

                break;
            }
        }

        $entries = $fromPersisted;

        if (! $hasMicrosoftEntraInPersisted) {
            $legacy = data_get($raw, 'integrations.microsoft_entra');
            if ($this->legacyMicrosoftEntraIsMeaningful(is_array($legacy) ? $legacy : [])) {
                $entries[] = [
                    'id' => self::LEGACY_MICROSOFT_ENTRA_ROW_ID,
                    'type' => IntegrationType::MICROSOFT_ENTRA,
                    'config' => $this->normalizeLegacyEntraConfig($legacy),
                ];
            }
        }

        return $entries;
    }

    /**
     * @return list<array{id: string, type: string, config: array<string, mixed>}>
     */
    private function persistedSettingsItems(array $raw): array
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
    private function legacyMicrosoftEntraIsMeaningful(array $legacy): bool
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
    private function normalizeLegacyEntraConfig(array $legacy): array
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
}
