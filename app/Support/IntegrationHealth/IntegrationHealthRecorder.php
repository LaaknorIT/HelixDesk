<?php

namespace App\Support\IntegrationHealth;

use App\Models\OrganizationIntegration;
use Illuminate\Support\Carbon;

/**
 * Stores what sync runs and connection checks learn about an integration's health.
 */
final class IntegrationHealthRecorder
{
    /**
     * @param  list<string>|null  $grantedPermissions  null when the permissions could not be read
     */
    public static function recordGrantedPermissions(string $integrationId, ?array $grantedPermissions, ?Carbon $secretExpiresAt = null): void
    {
        $integration = self::find($integrationId);
        if ($integration === null) {
            return;
        }

        $patch = [
            'granted_permissions' => $grantedPermissions,
            'permissions_checked_at' => now()->toIso8601String(),
        ];

        if ($secretExpiresAt !== null) {
            $patch['detected_client_secret_expires_at'] = $secretExpiresAt->toIso8601String();
        }

        $integration->mergeHealthMetadata($patch);
    }

    /**
     * Permissions granted on the Microsoft Defender for Endpoint API, read from a Defender token. They are stored apart
     * from the Microsoft Graph permissions because the two APIs issue separate tokens.
     *
     * @param  list<string>|null  $grantedPermissions
     */
    public static function recordGrantedDefenderPermissions(string $integrationId, ?array $grantedPermissions): void
    {
        self::find($integrationId)?->mergeHealthMetadata([
            'granted_defender_permissions' => $grantedPermissions,
            'defender_permissions_checked_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Record (or clear, with a null message) a warning for one capability that could not be synced
     * while the rest of the integration kept working.
     */
    public static function recordScopeWarning(string $integrationId, string $scopeKey, ?string $message): void
    {
        $integration = self::find($integrationId);
        if ($integration === null) {
            return;
        }

        $warnings = is_array($integration->healthMetadata()['scope_warnings'] ?? null)
            ? $integration->healthMetadata()['scope_warnings']
            : [];

        if ($message === null) {
            if (! array_key_exists($scopeKey, $warnings)) {
                return;
            }

            unset($warnings[$scopeKey]);
        } else {
            $warnings[$scopeKey] = [
                'message' => $message,
                'recorded_at' => now()->toIso8601String(),
            ];
        }

        $integration->mergeHealthMetadata(['scope_warnings' => $warnings]);
    }

    public static function recordConnectionCheck(string $integrationId, bool $ok, string $message): void
    {
        $integration = self::find($integrationId);
        if ($integration === null) {
            return;
        }

        $integration->mergeHealthMetadata([
            'connection_check' => [
                'ok' => $ok,
                'message' => $message,
                'checked_at' => now()->toIso8601String(),
            ],
        ]);
    }

    private static function find(string $integrationId): ?OrganizationIntegration
    {
        return OrganizationIntegration::query()->withoutGlobalScopes()->find($integrationId);
    }
}
