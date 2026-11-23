<?php

namespace App\Support;

use App\Models\Organization;
use Filament\Facades\Filament;
use Illuminate\Support\Str;

final class OrganizationIntegrationOptions
{
    /**
     * Integration types that can power API-based mail transports today or in planned releases.
     *
     * @return list<string>
     */
    public static function mailTransportTypes(): array
    {
        return [
            OrganizationIntegrationSettings::TYPE_MICROSOFT_ENTRA,
            OrganizationIntegrationSettings::TYPE_GOOGLE_WORKSPACE,
        ];
    }

    /**
     * @param  list<string>  $allowedTypes
     * @return array<string, string> integration_id => label
     */
    public static function forOrganization(?int $organizationId, array $allowedTypes): array
    {
        if ($organizationId === null || $allowedTypes === []) {
            return [];
        }

        $organization = Organization::query()->find($organizationId);
        if ($organization === null) {
            return [];
        }

        $options = [];
        foreach (OrganizationIntegrationSettings::listEntriesForOrganization($organization) as $entry) {
            $type = (string) ($entry['type'] ?? '');
            if (! in_array($type, $allowedTypes, true)) {
                continue;
            }

            $integrationId = (string) ($entry['id'] ?? '');
            if ($integrationId === '') {
                continue;
            }

            $options[$integrationId] = self::formatIntegrationLabel($entry);
        }

        return $options;
    }

    /**
     * @param  array{id?: string, type?: string, config?: array<string, mixed>}  $entry
     */
    private static function formatIntegrationLabel(array $entry): string
    {
        $type = OrganizationIntegrationSettings::typeLabel((string) ($entry['type'] ?? ''));
        $config = is_array($entry['config'] ?? null) ? $entry['config'] : [];

        $detail = match ($entry['type'] ?? '') {
            OrganizationIntegrationSettings::TYPE_MICROSOFT_ENTRA => trim((string) ($config['azure_tenant_id'] ?? '')),
            OrganizationIntegrationSettings::TYPE_GOOGLE_WORKSPACE => trim((string) ($config['customer_domain'] ?? '')),
            OrganizationIntegrationSettings::TYPE_ACTION1 => trim((string) ($config['action1_organization_id'] ?? '')),
            OrganizationIntegrationSettings::TYPE_ACTIVE_DIRECTORY => trim((string) ($config['domain_fqdn'] ?? '')),
            default => '',
        };

        if ($detail === '') {
            return $type;
        }

        return $type.' — '.Str::limit($detail, 36);
    }

    /**
     * @return array<int, string> organization_id => label
     */
    public static function organizationsForCurrentTenant(): array
    {
        $tenant = Filament::getTenant();
        if ($tenant === null) {
            return [];
        }

        return Organization::query()
            ->where('tenant_id', $tenant->getKey())
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * Organizations with their place in the tree ("MSP › Customer A › Sales"), sorted by that path, so an agent can
     * tell levels apart when choosing where an integration belongs.
     *
     * @return array<int, string> organization_id => path
     */
    public static function organizationPathsForCurrentTenant(): array
    {
        $tenant = Filament::getTenant();
        if ($tenant === null) {
            return [];
        }

        $organizations = Organization::query()
            ->where('tenant_id', $tenant->getKey())
            ->get(['id', 'parent_id', 'name'])
            ->keyBy(fn (Organization $organization): int => (int) $organization->getKey());

        $lineage = new OrganizationLineage;
        $paths = [];
        foreach ($organizations as $id => $organization) {
            $names = array_map(
                fn (int $ancestorId): string => (string) ($organizations->get($ancestorId)?->name ?? ''),
                array_reverse($lineage->ancestorIds((int) $tenant->getKey(), $id)),
            );
            $names[] = (string) $organization->name;
            $paths[$id] = implode(' › ', array_filter($names, fn (string $name): bool => $name !== ''));
        }

        asort($paths, SORT_NATURAL | SORT_FLAG_CASE);

        return $paths;
    }

    public static function resolveType(?int $organizationId, ?string $integrationId): ?string
    {
        if ($organizationId === null || $integrationId === null || $integrationId === '') {
            return null;
        }

        $organization = Organization::query()->find($organizationId);
        if ($organization === null) {
            return null;
        }

        $entry = OrganizationIntegrationSettings::findEntryForOrganization($organization, $integrationId);

        $type = is_array($entry) ? (string) ($entry['type'] ?? '') : '';

        return $type !== '' ? $type : null;
    }
}
