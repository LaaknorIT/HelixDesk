<?php

namespace App\Services\ActiveDirectory;

use App\Data\AssetProviderSnapshot;
use App\Data\DeviceSourceRecordData;
use App\Enums\AssetExternalIdentifierType;
use App\Enums\DeviceClass;
use App\Models\AssetSourceRecord;
use App\Models\AssetType;
use App\Models\CollectorRun;
use App\Models\OrganizationIntegration;
use App\Services\AssetReconciliationService;
use App\Services\DeviceSourceRecordService;
use App\Services\TenantContext;
use App\Support\AssetIdentityNormalizer;
use App\Support\DeviceSourceKey;
use App\Support\IntegrationType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Turns computer objects pushed by the Active Directory collector into source records and (for active computers,
 * by default) assets. Disabled computers and computers that have not been seen for a long time are skipped, so old
 * objects nobody cleaned out of AD never show up in HelixDesk.
 */
final class ActiveDirectoryInventoryService
{
    public const DEFAULT_SKIP_INACTIVE_AFTER_DAYS = 90;

    public const SKIP_REASON_DISABLED = 'disabled';

    public const SKIP_REASON_INACTIVE = 'inactive';

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AssetReconciliationService $assetReconciliationService,
        private readonly DeviceSourceRecordService $deviceSourceRecordService,
    ) {}

    public function startRun(OrganizationIntegration $integration, ?string $collectorVersion, ?string $collectorHost): CollectorRun
    {
        CollectorRun::query()
            ->withoutGlobalScopes()
            ->where('organization_integration_id', $integration->getKey())
            ->where('status', CollectorRun::STATUS_RUNNING)
            ->update(['status' => CollectorRun::STATUS_INCOMPLETE, 'error' => 'Superseded by a newer run.', 'updated_at' => now()]);

        return CollectorRun::query()->create([
            'tenant_id' => $integration->tenant_id,
            'organization_integration_id' => $integration->getKey(),
            'status' => CollectorRun::STATUS_RUNNING,
            'collector_version' => $collectorVersion,
            'collector_host' => $collectorHost,
            'started_at' => now(),
            'batch_sequences' => [],
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $objects
     * @return int number of computer objects stored
     */
    public function ingestBatch(OrganizationIntegration $integration, CollectorRun $run, int $sequence, array $objects): int
    {
        $sequences = $run->batch_sequences ?? [];
        if (in_array($sequence, $sequences, true)) {
            return 0;
        }

        $this->tenantContext->setTenantId((int) $integration->tenant_id);

        try {
            $assetTypeId = (int) AssetType::query()
                ->withoutGlobalScopes()
                ->firstOrCreate(['tenant_id' => $integration->tenant_id, 'name' => 'Computer'])
                ->getKey();

            $config = $integration->mergedConfig();
            $stored = 0;
            $skipped = 0;
            foreach ($objects as $object) {
                $snapshot = $this->snapshot($integration, $assetTypeId, $object);
                if ($snapshot === null) {
                    continue;
                }

                if (self::skipReason($config, $snapshot->sourceRecord?->enabled, $snapshot->sourceRecord?->lastActivityAt, $object) !== null) {
                    // Counted as received so the run still completes; the record is then marked gone like a
                    // computer deleted from AD.
                    $skipped++;

                    continue;
                }

                $this->assetReconciliationService->reconcile($snapshot);
                $stored++;
            }
        } finally {
            $this->tenantContext->setTenantId(null);
        }

        $run->batch_sequences = [...$sequences, $sequence];
        $run->received_count += $stored + $skipped;
        $run->skipped_count += $skipped;
        $run->save();

        return $stored;
    }

    /**
     * Days without activity after which a computer is skipped, or 0 to keep every computer.
     *
     * @param  array<string, mixed>  $config
     */
    public static function skipInactiveAfterDays(array $config): int
    {
        return max(0, (int) ($config['skip_inactive_after_days'] ?? self::DEFAULT_SKIP_INACTIVE_AFTER_DAYS));
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function skipsDisabled(array $config): bool
    {
        return (bool) ($config['skip_disabled'] ?? true);
    }

    /**
     * Why a computer is left out of HelixDesk, or null to import it. Domain controllers are always imported. A
     * computer that has never logged on counts from when it was created, so a newly joined PC is not skipped.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $object  the collector object (for is_domain_controller and when_created)
     */
    public static function skipReason(array $config, ?bool $enabled, ?Carbon $lastActivity, array $object = []): ?string
    {
        if ((bool) ($object['is_domain_controller'] ?? false)) {
            return null;
        }

        if ($enabled === false && self::skipsDisabled($config)) {
            return self::SKIP_REASON_DISABLED;
        }

        $days = self::skipInactiveAfterDays($config);
        if ($days === 0) {
            return null;
        }

        $seen = $lastActivity ?? DeviceSourceRecordData::parseActivityTime($object['when_created'] ?? null);

        return $seen === null || $seen->lessThan(now()->subDays($days)) ? self::SKIP_REASON_INACTIVE : null;
    }

    /**
     * Completes a run. Only when HelixDesk received every object the collector says it sent are computers that
     * were not in the run marked gone, so an interrupted upload never removes anything.
     */
    public function completeRun(OrganizationIntegration $integration, CollectorRun $run, int $expectedCount): CollectorRun
    {
        $run->expected_count = $expectedCount;
        $run->completed_at = now();

        if ($run->received_count < $expectedCount) {
            $run->status = CollectorRun::STATUS_INCOMPLETE;
            $run->error = sprintf('Received %d of %d objects.', $run->received_count, $expectedCount);
            $run->save();

            $integration->last_sync_error = __('The last collector run was incomplete: HelixDesk received :received of :expected computers. Nothing was marked as removed.', [
                'received' => $run->received_count,
                'expected' => $expectedCount,
            ]);
            $integration->last_sync_failed_at = now();
            $integration->save();

            return $run;
        }

        $run->status = CollectorRun::STATUS_COMPLETED;
        $run->save();

        $this->deviceSourceRecordService->markMissingAsGone(
            (int) $integration->tenant_id,
            (string) $integration->getKey(),
            DeviceSourceKey::ACTIVE_DIRECTORY,
            $run->started_at,
        );

        $integration->last_sync_at = now();
        $integration->last_sync_error = null;
        $integration->save();

        Cache::forget('device-coverage:missing:'.$integration->tenant_id.':'.$integration->organization_id);

        return $run;
    }

    /**
     * @param  array<string, mixed>  $object
     */
    public function snapshot(OrganizationIntegration $integration, int $assetTypeId, array $object): ?AssetProviderSnapshot
    {
        $guid = mb_strtolower(trim((string) ($object['object_guid'] ?? '')));
        if ($guid === '') {
            return null;
        }

        $config = $integration->mergedConfig();
        $threshold = (int) ($config['active_threshold_days'] ?? AssetSourceRecord::DEFAULT_ACTIVE_THRESHOLD_DAYS);
        $createAssets = (string) ($config['create_assets'] ?? 'active_only');

        $name = trim((string) ($object['name'] ?? ''));
        $fqdn = trim((string) ($object['dns_host_name'] ?? ''));
        $sid = trim((string) ($object['object_sid'] ?? ''));
        $operatingSystem = trim(implode(' ', array_filter([
            trim((string) ($object['operating_system'] ?? '')),
            trim((string) ($object['operating_system_version'] ?? '')),
        ]))) ?: null;
        $enabled = array_key_exists('enabled', $object) ? (bool) $object['enabled'] : null;
        $isDomainController = (bool) ($object['is_domain_controller'] ?? false);

        $lastActivity = collect([
            DeviceSourceRecordData::parseActivityTime($object['last_logon_timestamp'] ?? null),
            DeviceSourceRecordData::parseActivityTime($object['pwd_last_set'] ?? null),
        ])->filter()->sortDesc()->first();

        $isActive = $enabled !== false
            && $lastActivity instanceof Carbon
            && $lastActivity->greaterThanOrEqualTo(now()->subDays($threshold));

        $hostname = $fqdn !== '' ? $fqdn : $name;
        $identifiers = [['type' => AssetExternalIdentifierType::AdObjectGuid, 'value' => $guid]];
        if ($sid !== '') {
            $identifiers[] = ['type' => AssetExternalIdentifierType::AdObjectSid, 'value' => $sid];
        }
        if (AssetIdentityNormalizer::normalizeHostname($hostname) !== null) {
            $identifiers[] = ['type' => AssetExternalIdentifierType::Hostname, 'value' => $hostname];
        }

        $deviceClass = $isDomainController ? DeviceClass::Server : DeviceClass::fromOperatingSystem($operatingSystem);

        return new AssetProviderSnapshot(
            tenantId: (int) $integration->tenant_id,
            organizationId: (int) $integration->organization_id,
            organizationIntegrationId: (string) $integration->getKey(),
            provider: IntegrationType::ACTIVE_DIRECTORY,
            identifiers: $identifiers,
            attributes: array_filter([
                'name' => $name !== '' ? $name : $guid,
                'primary_hostname' => $hostname !== '' ? $hostname : null,
                'operating_system' => $operatingSystem,
                'lifecycle_status' => 'in_use',
                'asset_type_id' => $assetTypeId,
            ], fn (mixed $value): bool => $value !== null && $value !== ''),
            sourceRecord: new DeviceSourceRecordData(
                source: DeviceSourceKey::ACTIVE_DIRECTORY,
                providerRecordId: $guid,
                displayName: $name !== '' ? $name : null,
                hostname: $hostname !== '' ? $hostname : null,
                fqdn: $fqdn !== '' ? $fqdn : null,
                operatingSystem: $operatingSystem,
                deviceClass: $deviceClass,
                enabled: $enabled,
                lastActivityAt: $lastActivity,
                metadata: array_filter([
                    'distinguished_name' => $object['distinguished_name'] ?? null,
                    'object_sid' => $sid !== '' ? $sid : null,
                    'description' => $object['description'] ?? null,
                    'when_created' => $object['when_created'] ?? null,
                    'is_domain_controller' => $isDomainController ?: null,
                    'domain' => $config['domain_fqdn'] ?? null,
                ], fn (mixed $value): bool => $value !== null && $value !== ''),
            ),
            createAssetIfUnmatched: match ($createAssets) {
                'all' => true,
                'never' => false,
                default => $isActive,
            },
            matchByUniqueHostname: true,
        );
    }
}
