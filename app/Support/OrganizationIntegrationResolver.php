<?php

namespace App\Support;

use App\Models\MailAccount;
use App\Models\Organization;
use RuntimeException;

/**
 * Resolves organization integration entries referenced by mail accounts and other features.
 */
final class OrganizationIntegrationResolver
{
    /**
     * @return array{id: string, type: string, config: array<string, mixed>}
     */
    public static function resolveMailAccountIntegration(MailAccount $account): array
    {
        if ($account->organization_id === null) {
            throw new RuntimeException('Mail account is missing organization reference.');
        }

        if ($account->integration_id === null || $account->integration_type === null) {
            throw new RuntimeException('Mail account is missing organization integration reference.');
        }

        return self::resolve(
            organizationId: (int) $account->organization_id,
            integrationId: (string) $account->integration_id,
            integrationType: (string) $account->integration_type,
        );
    }

    /**
     * @return array{id: string, type: string, config: array<string, mixed>}
     */
    public static function resolve(int $organizationId, string $integrationId, string $integrationType): array
    {
        $organization = Organization::query()->find($organizationId);
        if ($organization === null) {
            throw new RuntimeException('Organization not found for integration reference.');
        }

        $entry = OrganizationIntegrationSettings::findEntry(
            is_array($organization->organizational_settings) ? $organization->organizational_settings : [],
            $integrationId,
        );

        if ($entry === null) {
            throw new RuntimeException('Organization integration not found.');
        }

        if (($entry['type'] ?? '') !== $integrationType) {
            throw new RuntimeException('Organization integration type does not match the referenced integration.');
        }

        return $entry;
    }
}
