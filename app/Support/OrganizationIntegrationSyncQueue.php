<?php

namespace App\Support;

use App\Jobs\SyncNextAction1IntegrationJob;
use App\Jobs\SyncNextMicrosoftEntraIntegrationJob;
use App\Models\Organization;

final class OrganizationIntegrationSyncQueue
{
    /**
     * @param  array<string, mixed>  $config
     * @return array{queued: bool, skip_reason: ?string}
     */
    public static function dispatch(Organization $organization, string $integrationId, string $type, array $config): array
    {
        if ($organization->tenant_id === null) {
            return [
                'queued' => false,
                'skip_reason' => 'organization_has_no_tenant',
            ];
        }

        $skipReason = self::syncSkipReason($type, $config);

        if ($skipReason !== null) {
            return [
                'queued' => false,
                'skip_reason' => $skipReason,
            ];
        }

        match ($type) {
            OrganizationIntegrationSettings::TYPE_MICROSOFT_ENTRA => SyncNextMicrosoftEntraIntegrationJob::dispatch(
                (int) $organization->getKey(),
                $integrationId,
            ),
            OrganizationIntegrationSettings::TYPE_ACTION1 => SyncNextAction1IntegrationJob::dispatch(
                (int) $organization->getKey(),
                $integrationId,
            ),
            default => null,
        };

        return [
            'queued' => true,
            'skip_reason' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function syncSkipReason(string $type, array $config): ?string
    {
        return match ($type) {
            OrganizationIntegrationSettings::TYPE_MICROSOFT_ENTRA => OrganizationIntegrationSettings::entraConfigSyncSkipReason($config),
            OrganizationIntegrationSettings::TYPE_ACTION1 => OrganizationIntegrationSettings::action1ConfigSyncSkipReason($config),
            default => 'unsupported_sync_type',
        };
    }

    public static function skipReasonMessage(string $skipReason): string
    {
        return match ($skipReason) {
            'integration_disabled' => __('Enable the integration before running sync.'),
            'missing_azure_tenant_id' => __('Configure the Azure tenant ID before running sync.'),
            'missing_application_client_id' => __('Configure the application client ID before running sync.'),
            'missing_client_secret' => __('Configure the client secret before running sync.'),
            'no_sync_scopes_enabled' => __('Enable at least one sync scope before running sync.'),
            'missing_action1_organization_id' => __('Configure the Action1 organization ID before running sync.'),
            'missing_client_id' => __('Configure the Action1 client ID before running sync.'),
            'missing_client_secret_or_api_key' => __('Configure Action1 credentials before running sync.'),
            'unsupported_sync_type' => __('This integration type does not support manual asset sync.'),
            'organization_has_no_tenant' => __('This organization is not linked to a tenant.'),
            default => __('Sync cannot run (:reason).', ['reason' => $skipReason]),
        };
    }
}
