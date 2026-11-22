<?php

namespace App\Support\IntegrationHealth;

use App\Enums\IntegrationHealthSeverity;
use App\Enums\IntegrationHealthStatus;
use App\Models\OrganizationIntegration;
use App\Support\IntegrationCatalog;
use App\Support\IntegrationType;
use App\Support\MicrosoftEntraIntegrationCatalog;
use App\Support\OrganizationIntegrationSyncQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Works out whether an integration is healthy, needs attention (warnings) or is failing (errors),
 * and explains why in words an administrator can act on.
 */
final class IntegrationHealthInspector
{
    public const SECRET_EXPIRY_WARNING_DAYS = 30;

    public const STALE_SYNC_MULTIPLIER = 3;

    /**
     * @param  iterable<OrganizationIntegration>  $integrations
     * @return Collection<int, IntegrationHealthReport>
     */
    public static function inspectMany(iterable $integrations): Collection
    {
        $reports = [];
        foreach ($integrations as $integration) {
            $reports[] = self::inspect($integration);
        }

        return collect($reports);
    }

    public static function inspect(OrganizationIntegration $integration): IntegrationHealthReport
    {
        if (! $integration->enabled) {
            return new IntegrationHealthReport($integration, IntegrationHealthStatus::Disabled, []);
        }

        $config = $integration->configWithCredentials();
        $config['enabled'] = true;
        $metadata = $integration->healthMetadata();

        $issues = [
            ...self::setupIssues($integration, $config),
            ...self::syncErrorIssues($integration, $metadata),
            ...self::secretExpiryIssues($integration, $config, $metadata),
            ...self::permissionIssues($integration, $config, $metadata),
            ...self::scopeWarningIssues($integration, $metadata),
            ...self::staleSyncIssues($integration, $config),
        ];

        $status = IntegrationHealthStatus::Healthy;
        foreach ($issues as $issue) {
            if ($issue->isError()) {
                $status = IntegrationHealthStatus::Error;

                break;
            }

            $status = IntegrationHealthStatus::Warning;
        }

        return new IntegrationHealthReport($integration, $status, $issues);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<IntegrationHealthIssue>
     */
    private static function setupIssues(OrganizationIntegration $integration, array $config): array
    {
        if (! in_array($integration->type, [IntegrationType::MICROSOFT_ENTRA, IntegrationType::ACTION1], true)) {
            return [];
        }

        $skipReason = OrganizationIntegrationSyncQueue::syncSkipReason((string) $integration->type, $config);
        if ($skipReason === null) {
            return [];
        }

        $scopes = is_array($config['scopes'] ?? null) ? $config['scopes'] : [];
        if ($skipReason === 'no_sync_scopes_enabled' && $integration->isMicrosoftEntra() && ($scopes['mail'] ?? false)) {
            return [];
        }

        return [new IntegrationHealthIssue(
            code: 'setup_incomplete',
            severity: IntegrationHealthSeverity::Error,
            title: __('Setup is incomplete'),
            detail: OrganizationIntegrationSyncQueue::skipReasonMessage($skipReason),
        )];
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return list<IntegrationHealthIssue>
     */
    private static function syncErrorIssues(OrganizationIntegration $integration, array $metadata): array
    {
        $issues = [];
        $error = trim((string) $integration->last_sync_error);

        if ($error !== '') {
            $hint = IntegrationErrorExplainer::hint($error);
            $issues[] = new IntegrationHealthIssue(
                code: 'sync_failed',
                severity: IntegrationHealthSeverity::Error,
                title: __('Last sync failed'),
                detail: $hint !== null
                    ? $hint.' '.__('Provider response: :error', ['error' => IntegrationErrorExplainer::summary($error)])
                    : IntegrationErrorExplainer::summary($error, 400),
                occurredAt: $integration->last_sync_failed_at,
            );
        }

        $check = is_array($metadata['connection_check'] ?? null) ? $metadata['connection_check'] : null;
        if ($check !== null && ! ($check['ok'] ?? true)) {
            $checkedAt = self::parseDate($check['checked_at'] ?? null);
            $supersededBySync = $checkedAt !== null && $integration->last_sync_at !== null && $integration->last_sync_at->gt($checkedAt);

            if (! $supersededBySync) {
                $message = (string) ($check['message'] ?? '');
                $issues[] = new IntegrationHealthIssue(
                    code: 'connection_check_failed',
                    severity: IntegrationHealthSeverity::Error,
                    title: __('Connection check failed'),
                    detail: IntegrationErrorExplainer::hint($message) ?? IntegrationErrorExplainer::summary($message, 400),
                    occurredAt: $checkedAt,
                );
            }
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $metadata
     * @return list<IntegrationHealthIssue>
     */
    private static function secretExpiryIssues(OrganizationIntegration $integration, array $config, array $metadata): array
    {
        $expiresAt = self::parseDate($config['client_secret_expires_at'] ?? null)
            ?? self::parseDate($metadata['detected_client_secret_expires_at'] ?? null);

        if ($expiresAt === null) {
            return [];
        }

        if ($expiresAt->isPast()) {
            return [new IntegrationHealthIssue(
                code: 'secret_expired',
                severity: IntegrationHealthSeverity::Error,
                title: __('Client secret has expired'),
                detail: __('The client secret expired on :date. Create a new secret and update the integration.', [
                    'date' => $expiresAt->toFormattedDateString(),
                ]),
                occurredAt: $expiresAt,
            )];
        }

        $daysLeft = (int) ceil(now()->diffInDays($expiresAt));
        if ($daysLeft > self::SECRET_EXPIRY_WARNING_DAYS) {
            return [];
        }

        return [new IntegrationHealthIssue(
            code: 'secret_expiring',
            severity: IntegrationHealthSeverity::Warning,
            title: __('Client secret expires soon'),
            detail: trans_choice(
                '{1} The client secret expires tomorrow (:date). Create a new secret and update the integration before it stops working.|[2,*] The client secret expires in :count days (:date). Create a new secret and update the integration before it stops working.',
                max(1, $daysLeft),
                ['date' => $expiresAt->toFormattedDateString()],
            ),
            occurredAt: $expiresAt,
        )];
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $metadata
     * @return list<IntegrationHealthIssue>
     */
    private static function permissionIssues(OrganizationIntegration $integration, array $config, array $metadata): array
    {
        if (! $integration->isMicrosoftEntra() || ! is_array($metadata['granted_permissions'] ?? null)) {
            return [];
        }

        $granted = array_values(array_filter($metadata['granted_permissions'], 'is_string'));
        $scopes = is_array($config['scopes'] ?? null) ? $config['scopes'] : [];
        $issues = [];

        foreach (array_keys(MicrosoftEntraIntegrationCatalog::runtimePermissionRequirements()) as $scopeKey) {
            if (! ($scopes[$scopeKey] ?? false)) {
                continue;
            }

            $missing = MicrosoftEntraIntegrationCatalog::missingPermissionsForScope($scopeKey, $granted);
            if ($missing === []) {
                continue;
            }

            $issues[] = new IntegrationHealthIssue(
                code: 'missing_permission:'.$scopeKey,
                severity: IntegrationHealthSeverity::Warning,
                title: __('Missing API permission for :capability', [
                    'capability' => IntegrationCatalog::capabilityLabel((string) $integration->type, $scopeKey),
                ]),
                detail: __('You asked HelixDesk to sync :capability, but the app registration has not been granted :permissions. Add the permission under API permissions in Microsoft Entra and grant admin consent. Until then this capability is skipped.', [
                    'capability' => mb_strtolower(IntegrationCatalog::capabilityLabel((string) $integration->type, $scopeKey)),
                    'permissions' => implode(', ', $missing),
                ]),
                occurredAt: self::parseDate($metadata['permissions_checked_at'] ?? null),
            );
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return list<IntegrationHealthIssue>
     */
    private static function scopeWarningIssues(OrganizationIntegration $integration, array $metadata): array
    {
        $warnings = is_array($metadata['scope_warnings'] ?? null) ? $metadata['scope_warnings'] : [];
        $issues = [];

        foreach ($warnings as $scopeKey => $warning) {
            if (! is_array($warning)) {
                continue;
            }

            $issues[] = new IntegrationHealthIssue(
                code: 'scope_warning:'.$scopeKey,
                severity: IntegrationHealthSeverity::Warning,
                title: __(':capability could not be synced', [
                    'capability' => IntegrationCatalog::capabilityLabel((string) $integration->type, (string) $scopeKey),
                ]),
                detail: (string) ($warning['message'] ?? ''),
                occurredAt: self::parseDate($warning['recorded_at'] ?? null),
            );
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<IntegrationHealthIssue>
     */
    private static function staleSyncIssues(OrganizationIntegration $integration, array $config): array
    {
        if (filled($integration->last_sync_error)) {
            return [];
        }

        if (! in_array($integration->type, [IntegrationType::MICROSOFT_ENTRA, IntegrationType::ACTION1], true)
            || OrganizationIntegrationSyncQueue::syncSkipReason((string) $integration->type, $config) !== null) {
            return [];
        }

        $intervalHours = max(1, (int) ($integration->sync_interval_hours ?? match ($integration->type) {
            IntegrationType::ACTION1 => config('helixdesk_integrations.action1.import_interval_hours', 6),
            default => config('helixdesk_integrations.entra.import_interval_hours', 24),
        }));
        $staleAfterHours = $intervalHours * self::STALE_SYNC_MULTIPLIER;

        if ($integration->last_sync_at === null) {
            if ($integration->created_at === null || $integration->created_at->gt(now()->subHours($staleAfterHours))) {
                return [];
            }

            return [new IntegrationHealthIssue(
                code: 'never_synced',
                severity: IntegrationHealthSeverity::Warning,
                title: __('Has never synced'),
                detail: __('The integration is enabled but has not completed a sync yet. Check that the queue worker and scheduler are running, or run a sync manually.'),
            )];
        }

        if ($integration->last_sync_at->gt(now()->subHours($staleAfterHours))) {
            return [];
        }

        return [new IntegrationHealthIssue(
            code: 'sync_stale',
            severity: IntegrationHealthSeverity::Warning,
            title: __('Sync is overdue'),
            detail: __('The last successful sync was :ago, but it should run every :hours hours. Check that the queue worker and scheduler are running.', [
                'ago' => $integration->last_sync_at->diffForHumans(),
                'hours' => $intervalHours,
            ]),
            occurredAt: $integration->last_sync_at,
        )];
    }

    private static function parseDate(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
