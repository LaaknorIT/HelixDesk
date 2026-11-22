<?php

namespace App\Support\IntegrationHealth;

use App\Enums\IntegrationHealthSeverity;
use App\Enums\IntegrationHealthStatus;
use App\Models\OrganizationIntegration;
use App\Services\ActiveDirectory\CollectorKeyService;
use App\Services\DeviceCoverageService;
use App\Support\DeviceCoverage\CoverageRules;
use App\Support\DeviceSourceCatalog;
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
            ...self::coverageIssues($integration),
            ...self::collectorIssues($integration),
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

    /**
     * Push-based integrations (the Active Directory collector): no key yet, never reported, or silent for too long.
     *
     * @return list<IntegrationHealthIssue>
     */
    private static function collectorIssues(OrganizationIntegration $integration): array
    {
        if ($integration->type !== IntegrationType::ACTIVE_DIRECTORY) {
            return [];
        }

        if (! app(CollectorKeyService::class)->hasKey($integration)) {
            return [new IntegrationHealthIssue(
                code: 'collector_key_missing',
                severity: IntegrationHealthSeverity::Error,
                title: __('Collector key not created'),
                detail: __('Open the integration and choose "Create collector key", then install the collector on a domain-joined machine.'),
            )];
        }

        $intervalHours = max(1, (int) ($integration->sync_interval_hours ?? $integration->mergedConfig()['sync_interval_hours'] ?? 4));
        $staleAfterHours = $intervalHours * self::STALE_SYNC_MULTIPLIER;
        $collector = is_array($integration->healthMetadata()['collector'] ?? null) ? $integration->healthMetadata()['collector'] : [];
        $lastContact = collect([
            self::parseDate($collector['last_heartbeat_at'] ?? null),
            $integration->last_sync_at,
        ])->filter()->sortDesc()->first();

        if ($lastContact === null) {
            if ($integration->created_at !== null && $integration->created_at->gt(now()->subHours($staleAfterHours))) {
                return [];
            }

            return [new IntegrationHealthIssue(
                code: 'collector_never_reported',
                severity: IntegrationHealthSeverity::Warning,
                title: __('The collector has not reported yet'),
                detail: __('Install the collector on a domain-joined machine and check that it can reach HelixDesk over HTTPS.'),
            )];
        }

        $issues = [];
        if ($lastContact->lt(now()->subHours($staleAfterHours))) {
            $issues[] = new IntegrationHealthIssue(
                code: 'collector_silent',
                severity: IntegrationHealthSeverity::Error,
                title: __('The collector has stopped reporting'),
                detail: __('The collector on :host last reported :ago. Check that the scheduled task is still running and that the machine can reach HelixDesk.', [
                    'host' => (string) ($collector['host'] ?? __('the collector machine')),
                    'ago' => $lastContact->diffForHumans(),
                ]),
                occurredAt: $lastContact,
            );
        }

        $collectorError = trim((string) ($collector['last_error'] ?? ''));
        if ($collectorError !== '') {
            $issues[] = new IntegrationHealthIssue(
                code: 'collector_error',
                severity: IntegrationHealthSeverity::Warning,
                title: __('The collector reported an error'),
                detail: IntegrationErrorExplainer::summary($collectorError, 400),
            );
        }

        return $issues;
    }

    /**
     * Devices that other sources see as active but that this integration's sources do not report, per coverage
     * rule. The organization's coverage summary is cached briefly so listing many integrations stays cheap.
     *
     * @return list<IntegrationHealthIssue>
     */
    private static function coverageIssues(OrganizationIntegration $integration): array
    {
        $ownSources = DeviceSourceCatalog::enabledFor($integration);
        if ($ownSources === []) {
            return [];
        }

        $missing = DeviceCoverageService::cachedMissingCounts((int) $integration->tenant_id, (int) $integration->organization_id);
        $ownRoles = [];
        foreach ($ownSources as $key) {
            foreach (DeviceSourceCatalog::find($key)['roles'] ?? [] as $role) {
                $ownRoles[] = $role;
            }
        }

        $issues = [];
        foreach ($missing as $ruleKey => $count) {
            $rule = CoverageRules::find($ruleKey);
            if ($rule === null || $count < 1 || array_intersect(
                array_map(fn ($role) => $role->value, $rule->expectIn),
                array_map(fn ($role) => $role->value, $ownRoles),
            ) === []) {
                continue;
            }

            $issues[] = new IntegrationHealthIssue(
                code: 'coverage_gap',
                severity: IntegrationHealthSeverity::Warning,
                title: trans_choice('{1} 1 active device is missing here|[2,*] :count active devices are missing here', $count),
                detail: __(':rule: :cause Open Device coverage to see which devices.', [
                    'rule' => $rule->name,
                    'cause' => $rule->likelyCause,
                ]),
            );
        }

        return $issues;
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
