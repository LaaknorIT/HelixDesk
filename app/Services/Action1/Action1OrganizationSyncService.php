<?php

namespace App\Services\Action1;

use App\Data\AssetProviderSnapshot;
use App\Data\AssetReconciliationResult;
use App\Enums\AssetExternalIdentifierType;
use App\Enums\AssetPatchComplianceStatus;
use App\Enums\AssetSoftwareSource;
use App\Models\Asset;
use App\Models\AssetExternalIdentifier;
use App\Models\AssetPatchItem;
use App\Models\AssetPatchStatus;
use App\Models\AssetSoftwareInstallation;
use App\Models\AssetType;
use App\Models\MonitoringEvent;
use App\Models\MonitoringEventSource;
use App\Models\Organization;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AssetReconciliationService;
use App\Services\AssetRetirementService;
use App\Services\MonitoringEventAssetLinker;
use App\Services\TenantContext;
use App\Support\Action1SyncTarget;
use App\Support\AssetIdentityNormalizer;
use App\Support\IntegrationType;
use App\Support\OrganizationIntegrationSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

final class Action1OrganizationSyncService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AssetReconciliationService $assetReconciliationService,
        private readonly AssetRetirementService $assetRetirementService,
        private readonly MonitoringEventAssetLinker $monitoringEventAssetLinker,
    ) {}

    public function sync(Action1SyncTarget $target): void
    {
        $client = Action1HttpClient::fromTarget($target);
        $client->authenticate();

        $this->tenantContext->setTenantId($target->tenantId);
        setPermissionsTeamId($target->tenantId);

        try {
            $organization = Organization::query()->find($target->organizationId);
            if ($organization === null) {
                throw new \RuntimeException('Organization not found: '.$target->organizationId);
            }

            $scopes = is_array($target->config['scopes'] ?? null) ? $target->config['scopes'] : [];
            $action1OrganizationId = trim((string) ($target->config['action1_organization_id'] ?? ''));
            $groupId = trim((string) ($target->config['action1_group_id'] ?? ''));

            $allowedEndpointIds = null;
            if ($groupId !== '') {
                $allowedEndpointIds = $client->listGroupEndpointIds($action1OrganizationId, $groupId);
            }

            if (($scopes['endpoints'] ?? false) || ($scopes['software'] ?? false) || ($scopes['patches'] ?? false)) {
                $this->syncEndpoints(
                    $client,
                    $target,
                    $organization,
                    $action1OrganizationId,
                    $allowedEndpointIds,
                    syncSoftware: (bool) ($scopes['software'] ?? false),
                    syncPatches: (bool) ($scopes['patches'] ?? false),
                    syncEndpoints: (bool) ($scopes['endpoints'] ?? false),
                );
            }

            if (($scopes['alerts'] ?? false)) {
                $this->syncAlerts($client, $target, $organization, $action1OrganizationId);
            }

            OrganizationIntegrationSettings::patchAction1IntegrationConfig(
                $organization,
                $target->integrationId,
                [
                    'last_sync_at' => now()->toIso8601String(),
                    'last_action1_sync_at' => now()->toIso8601String(),
                    'last_sync_error' => null,
                    'last_action1_sync_error' => null,
                ],
            );

            $tenant = Tenant::query()->find($target->tenantId);
            if ($tenant !== null) {
                $this->assetRetirementService->evaluateTenant($tenant);
            }
        } finally {
            $this->tenantContext->setTenantId(null);
            setPermissionsTeamId(null);
        }
    }

    public function recordFailure(Action1SyncTarget $target, Throwable $exception): void
    {
        $this->tenantContext->setTenantId($target->tenantId);
        setPermissionsTeamId($target->tenantId);

        try {
            $organization = Organization::query()->find($target->organizationId);
            if ($organization === null) {
                return;
            }

            OrganizationIntegrationSettings::patchAction1IntegrationConfig(
                $organization,
                $target->integrationId,
                [
                    'last_sync_error' => Str::limit($exception->getMessage(), 2000),
                    'last_action1_sync_error' => Str::limit($exception->getMessage(), 2000),
                ],
            );
        } finally {
            $this->tenantContext->setTenantId(null);
            setPermissionsTeamId(null);
        }
    }

    /**
     * @param  list<string>|null  $allowedEndpointIds
     */
    private function syncEndpoints(
        Action1HttpClient $client,
        Action1SyncTarget $target,
        Organization $organization,
        string $action1OrganizationId,
        ?array $allowedEndpointIds,
        bool $syncSoftware,
        bool $syncPatches,
        bool $syncEndpoints,
    ): void {
        $assetType = AssetType::query()->firstOrCreate(
            [
                'tenant_id' => $target->tenantId,
                'name' => 'Computer',
            ],
            [],
        );

        foreach ($client->listManagedEndpoints($action1OrganizationId) as $endpoint) {
            $endpointId = trim((string) ($endpoint['id'] ?? ''));
            if ($endpointId === '') {
                continue;
            }

            if ($allowedEndpointIds !== null && ! in_array($endpointId, $allowedEndpointIds, true)) {
                continue;
            }

            if (! $syncEndpoints && ! $syncSoftware && ! $syncPatches) {
                continue;
            }

            $result = null;
            if ($syncEndpoints) {
                $result = $this->assetReconciliationService->reconcile(
                    $this->buildEndpointSnapshot($target, $organization, $assetType, $endpoint),
                );
            } else {
                $asset = $this->findAssetByAction1EndpointId($target, $endpointId);
                if ($asset === null) {
                    continue;
                }
                $result = new AssetReconciliationResult(
                    asset: $asset,
                    created: false,
                    matched: true,
                );
            }

            if ($syncSoftware) {
                $this->syncSoftwareInstallations($client, $target, $action1OrganizationId, $endpointId, $result->asset);
            }

            if ($syncPatches) {
                $this->syncPatchItems($client, $target, $action1OrganizationId, $endpointId, $result->asset);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $endpoint
     */
    private function buildEndpointSnapshot(
        Action1SyncTarget $target,
        Organization $organization,
        AssetType $assetType,
        array $endpoint,
    ): AssetProviderSnapshot {
        $endpointId = trim((string) ($endpoint['id'] ?? ''));
        $name = trim((string) ($endpoint['name'] ?? $endpoint['device_name'] ?? $endpointId));
        $serialRaw = trim((string) ($endpoint['serial'] ?? $endpoint['serial_number'] ?? ''));
        $serial = AssetIdentityNormalizer::normalizeSerial($serialRaw !== '' ? $serialRaw : null);
        $macRaw = trim((string) ($endpoint['mac'] ?? $endpoint['mac_address'] ?? $endpoint['MAC'] ?? ''));
        $mac = AssetIdentityNormalizer::normalizeMac($macRaw !== '' ? $macRaw : null);
        $hostnameRaw = trim((string) ($endpoint['name'] ?? $endpoint['hostname'] ?? $endpoint['device_name'] ?? ''));
        $normalizedHostname = AssetIdentityNormalizer::normalizeHostname($hostnameRaw !== '' ? $hostnameRaw : null);

        $identifiers = [[
            'type' => AssetExternalIdentifierType::Action1EndpointId,
            'value' => $endpointId,
        ]];

        if ($serial !== null) {
            $identifiers[] = [
                'type' => AssetExternalIdentifierType::SerialNumber,
                'value' => $serial,
            ];
        }

        if ($mac !== null) {
            $identifiers[] = [
                'type' => AssetExternalIdentifierType::MacAddress,
                'value' => $mac,
            ];
        }

        if ($normalizedHostname !== null) {
            $identifiers[] = [
                'type' => AssetExternalIdentifierType::Hostname,
                'value' => $hostnameRaw,
            ];
        }

        $azureAdDeviceId = trim((string) ($endpoint['azure_ad_device_id'] ?? data_get($endpoint, 'extended_fields.azure_ad_device_id') ?? ''));
        if ($azureAdDeviceId !== '') {
            $identifiers[] = [
                'type' => AssetExternalIdentifierType::EntraAzureAdDeviceId,
                'value' => $azureAdDeviceId,
            ];
        }

        $owner = $this->resolveOwner($target, $endpoint);
        $operatingSystem = $this->resolveOperatingSystem($endpoint);

        return new AssetProviderSnapshot(
            tenantId: $target->tenantId,
            organizationId: (int) $organization->getKey(),
            organizationIntegrationId: $target->integrationId,
            provider: IntegrationType::ACTION1,
            identifiers: $identifiers,
            attributes: array_filter([
                'name' => $name !== '' ? $name : $endpointId,
                'serial_number' => $serial,
                'primary_hostname' => $normalizedHostname !== null ? $hostnameRaw : null,
                'operating_system' => $operatingSystem,
                'owner_user_id' => $owner?->getKey(),
                'lifecycle_status' => 'in_use',
                'asset_type_id' => $assetType->getKey(),
            ], fn (mixed $value): bool => $value !== null && $value !== ''),
            satellite: [
                'metadata' => [
                    'status' => $endpoint['status'] ?? null,
                    'subscription_status' => $endpoint['subscription_status'] ?? null,
                    'last_seen' => $endpoint['last_seen'] ?? $endpoint['last_online'] ?? null,
                ],
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $endpoint
     */
    private function resolveOwner(Action1SyncTarget $target, array $endpoint): ?User
    {
        $candidates = [
            trim((string) ($endpoint['user'] ?? '')),
            trim((string) ($endpoint['user_email'] ?? '')),
            trim((string) data_get($endpoint, 'extended_fields.user_email') ?? ''),
        ];

        foreach ($candidates as $candidate) {
            if ($candidate === '' || ! filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            $owner = User::query()
                ->where('tenant_id', $target->tenantId)
                ->where('email', $candidate)
                ->first();

            if ($owner !== null) {
                return $owner;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $endpoint
     */
    private function resolveOperatingSystem(array $endpoint): ?string
    {
        $platform = trim((string) ($endpoint['platform'] ?? $endpoint['OS'] ?? ''));
        $version = trim((string) ($endpoint['OS_version'] ?? $endpoint['os_version'] ?? ''));

        if ($platform === '' && $version === '') {
            return null;
        }

        if ($platform !== '' && $version !== '') {
            return $platform.' '.$version;
        }

        return $platform !== '' ? $platform : $version;
    }

    private function findAssetByAction1EndpointId(Action1SyncTarget $target, string $endpointId): ?Asset
    {
        $external = AssetExternalIdentifier::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $target->tenantId)
            ->where('provider', IntegrationType::ACTION1)
            ->where('identifier_type', AssetExternalIdentifierType::Action1EndpointId)
            ->where('external_id', $endpointId)
            ->first();

        if ($external === null) {
            return null;
        }

        return Asset::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $target->tenantId)
            ->where('organization_id', $target->organizationId)
            ->whereKey($external->asset_id)
            ->first();
    }

    private function syncSoftwareInstallations(
        Action1HttpClient $client,
        Action1SyncTarget $target,
        string $action1OrganizationId,
        string $endpointId,
        Asset $asset,
    ): void {
        $seenExternalIds = [];
        $now = now();

        foreach ($client->listInstalledSoftware($action1OrganizationId, $endpointId) as $installation) {
            $externalId = trim((string) ($installation['id'] ?? $installation['app_id'] ?? ''));
            $name = trim((string) ($installation['name'] ?? $installation['app_name'] ?? ''));
            if ($name === '') {
                continue;
            }

            if ($externalId === '') {
                $externalId = sha1(strtolower($name.'|'.trim((string) ($installation['version'] ?? ''))));
            }

            $seenExternalIds[] = $externalId;

            AssetSoftwareInstallation::query()->updateOrCreate(
                [
                    'tenant_id' => $target->tenantId,
                    'asset_id' => $asset->getKey(),
                    'provider' => IntegrationType::ACTION1,
                    'external_id' => $externalId,
                ],
                [
                    'organization_integration_id' => $target->integrationId,
                    'name' => $name,
                    'version' => trim((string) ($installation['version'] ?? '')) ?: null,
                    'publisher' => trim((string) ($installation['publisher'] ?? $installation['vendor'] ?? '')) ?: null,
                    'install_date' => $this->parseDate($installation['install_date'] ?? $installation['installed_on'] ?? null),
                    'source' => AssetSoftwareSource::Discovered,
                    'last_seen_at' => $now,
                    'metadata_json' => $installation,
                ],
            );
        }

        if ($seenExternalIds !== []) {
            AssetSoftwareInstallation::query()
                ->where('tenant_id', $target->tenantId)
                ->where('asset_id', $asset->getKey())
                ->where('provider', IntegrationType::ACTION1)
                ->where('organization_integration_id', $target->integrationId)
                ->whereNotIn('external_id', $seenExternalIds)
                ->delete();
        }
    }

    private function syncPatchItems(
        Action1HttpClient $client,
        Action1SyncTarget $target,
        string $action1OrganizationId,
        string $endpointId,
        Asset $asset,
    ): void {
        $items = $client->listMissingUpdates($action1OrganizationId, $endpointId);
        $now = now();
        $seenKbIds = [];
        $criticalCount = 0;
        $otherCount = 0;

        $patchStatus = AssetPatchStatus::query()->updateOrCreate(
            [
                'tenant_id' => $target->tenantId,
                'asset_id' => $asset->getKey(),
                'provider' => IntegrationType::ACTION1,
                'organization_integration_id' => $target->integrationId,
            ],
            [
                'missing_critical_count' => 0,
                'missing_other_count' => 0,
                'last_assessed_at' => $now,
                'compliance_status' => AssetPatchComplianceStatus::Compliant,
            ],
        );

        foreach ($items as $item) {
            $kbId = trim((string) ($item['id'] ?? $item['kb_id'] ?? $item['KB'] ?? ''));
            if ($kbId === '') {
                continue;
            }

            $severity = strtolower(trim((string) ($item['severity'] ?? $item['importance'] ?? 'other')));
            if ($severity === 'critical') {
                $criticalCount++;
            } else {
                $otherCount++;
            }

            $seenKbIds[] = $kbId;

            AssetPatchItem::query()->updateOrCreate(
                [
                    'tenant_id' => $target->tenantId,
                    'asset_id' => $asset->getKey(),
                    'provider' => IntegrationType::ACTION1,
                    'organization_integration_id' => $target->integrationId,
                    'kb_id' => $kbId,
                ],
                [
                    'asset_patch_status_id' => $patchStatus->getKey(),
                    'cve_id' => $this->resolveCveId($item),
                    'title' => trim((string) ($item['title'] ?? $item['name'] ?? $kbId)),
                    'severity' => $this->normalizePatchSeverity($severity),
                    'approval_status' => trim((string) ($item['approval_status'] ?? $item['status'] ?? '')) ?: null,
                    'last_seen_at' => $now,
                    'metadata_json' => $item,
                ],
            );
        }

        if ($seenKbIds !== []) {
            AssetPatchItem::query()
                ->where('tenant_id', $target->tenantId)
                ->where('asset_id', $asset->getKey())
                ->where('provider', IntegrationType::ACTION1)
                ->where('organization_integration_id', $target->integrationId)
                ->whereNotIn('kb_id', $seenKbIds)
                ->delete();
        }

        $complianceStatus = match (true) {
            $criticalCount > 0 => AssetPatchComplianceStatus::CriticalMissing,
            $otherCount > 0 => AssetPatchComplianceStatus::UpdatesMissing,
            default => AssetPatchComplianceStatus::Compliant,
        };

        $patchStatus->update([
            'missing_critical_count' => $criticalCount,
            'missing_other_count' => $otherCount,
            'last_assessed_at' => $now,
            'compliance_status' => $complianceStatus,
        ]);
    }

    private function syncAlerts(
        Action1HttpClient $client,
        Action1SyncTarget $target,
        Organization $organization,
        string $action1OrganizationId,
    ): void {
        $source = $this->monitoringEventSource($target, $organization);

        foreach ($client->listAuditEvents($action1OrganizationId) as $event) {
            $alertId = trim((string) ($event['id'] ?? ''));
            if ($alertId === '') {
                continue;
            }

            $statusRaw = strtolower(trim((string) ($event['status'] ?? $event['state'] ?? 'open')));
            $closed = in_array($statusRaw, ['closed', 'resolved', 'completed'], true);
            $severity = $this->normalizeAlertSeverity($event['severity'] ?? $event['level'] ?? 'medium');
            $title = trim((string) ($event['title'] ?? $event['action'] ?? $event['description'] ?? 'Action1 alert'));
            $endpointId = trim((string) ($event['endpoint_id'] ?? data_get($event, 'object.endpoint_id') ?? ''));

            $existing = MonitoringEvent::query()
                ->where('tenant_id', $target->tenantId)
                ->where('monitoring_event_source_id', $source->getKey())
                ->where('external_reference', $alertId)
                ->first();

            $payload = [
                'tenant_id' => $target->tenantId,
                'monitoring_event_source_id' => $source->getKey(),
                'external_reference' => $alertId,
                'deduplication_key' => 'action1:'.$target->tenantId.':'.$target->integrationId.':'.$alertId,
                'severity' => $severity,
                'message' => $title,
                'status' => $closed ? 'closed' : 'open',
                'resolved_at' => $closed ? now() : null,
                'configuration_json' => array_filter([
                    'endpoint_id' => $endpointId !== '' ? $endpointId : null,
                    'organization_integration_id' => $target->integrationId,
                ]),
            ];

            if ($existing === null) {
                $event = MonitoringEvent::query()->create($payload);
            } else {
                $existing->update($payload);
                $event = $existing;
            }

            $this->monitoringEventAssetLinker->linkByAction1EndpointId($event, $endpointId);
        }
    }

    private function monitoringEventSource(Action1SyncTarget $target, Organization $organization): MonitoringEventSource
    {
        return MonitoringEventSource::query()->firstOrCreate(
            [
                'tenant_id' => $target->tenantId,
                'slug' => 'action1-alerts-org-'.$organization->getKey(),
            ],
            [
                'name' => 'Action1 alerts — '.$organization->name,
                'provider' => IntegrationType::ACTION1,
                'configuration_json' => [
                    'organization_id' => $organization->getKey(),
                    'organization_integration_id' => $target->integrationId,
                ],
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function resolveCveId(array $item): ?string
    {
        $cve = $item['cve_id'] ?? $item['CVE'] ?? null;
        if (is_string($cve) && trim($cve) !== '') {
            return trim($cve);
        }

        $cveIds = $item['cve_ids'] ?? null;
        if (is_array($cveIds) && isset($cveIds[0]) && is_string($cveIds[0])) {
            return trim($cveIds[0]);
        }

        return null;
    }

    private function normalizePatchSeverity(string $severity): string
    {
        return match ($severity) {
            'critical' => 'critical',
            'important', 'high' => 'important',
            'moderate', 'medium' => 'moderate',
            'low' => 'low',
            default => 'other',
        };
    }

    private function normalizeAlertSeverity(mixed $severity): string
    {
        $value = strtolower(trim((string) $severity));

        return match ($value) {
            'critical' => 'high',
            'high', 'important' => 'high',
            'medium', 'moderate' => 'medium',
            'low' => 'low',
            default => 'medium',
        };
    }

    private function parseDate(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }
}
