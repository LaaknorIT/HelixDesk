<?php

namespace App\Support;

use App\Enums\DeviceSourceRole;
use App\Models\OrganizationIntegration;

/**
 * The device sources that integrations register in IntegrationCatalog (`device_sources`). The coverage report
 * and its rules read sources from here, so adding an integration needs no change in the report.
 */
final class DeviceSourceCatalog
{
    /**
     * @return array<string, array{key: string, label: string, roles: list<DeviceSourceRole>, scope: string, integration_type: string, discovered_label: ?string}>
     */
    public static function all(): array
    {
        $sources = [];

        foreach (IntegrationCatalog::definitions() as $type => $definition) {
            foreach ($definition['device_sources'] ?? [] as $key => $source) {
                $sources[$key] = [
                    'key' => $key,
                    'label' => $source['label'],
                    'roles' => $source['roles'],
                    'scope' => $source['scope'],
                    'integration_type' => $type,
                    'discovered_label' => $source['discovered_label'] ?? null,
                ];
            }
        }

        return $sources;
    }

    /**
     * @return array{key: string, label: string, roles: list<DeviceSourceRole>, scope: string, integration_type: string, discovered_label: ?string}|null
     */
    public static function find(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    public static function label(string $key): string
    {
        return self::find($key)['label'] ?? $key;
    }

    /**
     * What a source calls a device it has seen but does not manage (for example "Not onboarded" for Defender).
     */
    public static function discoveredLabel(string $key): string
    {
        return self::find($key)['discovered_label'] ?? __('Seen, not managed');
    }

    /**
     * @return list<string> source keys
     */
    public static function withRole(DeviceSourceRole $role): array
    {
        return array_values(array_keys(array_filter(
            self::all(),
            fn (array $source): bool => in_array($role, $source['roles'], true),
        )));
    }

    /**
     * Sources an integration produces with its current settings: it must be enabled and have the source's scope on.
     *
     * @return list<string> source keys
     */
    public static function enabledFor(OrganizationIntegration $integration): array
    {
        if (! $integration->enabled) {
            return [];
        }

        $scopes = is_array($integration->config['scopes'] ?? null) ? $integration->config['scopes'] : [];

        return array_values(array_keys(array_filter(
            self::all(),
            fn (array $source): bool => $source['integration_type'] === $integration->type
                && (bool) ($scopes[$source['scope']] ?? false),
        )));
    }
}
