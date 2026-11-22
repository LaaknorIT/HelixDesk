<?php

namespace Tests\Feature;

use App\Data\AssetProviderSnapshot;
use App\Data\DeviceSourceRecordData;
use App\Enums\AssetExternalIdentifierType;
use App\Enums\DeviceClass;
use App\Enums\DeviceSourceRole;
use App\Models\Asset;
use App\Models\AssetSourceRecord;
use App\Models\AssetType;
use App\Models\Organization;
use App\Models\OrganizationIntegration;
use App\Models\Tenant;
use App\Services\AssetReconciliationService;
use App\Services\DeviceSourceRecordService;
use App\Support\DeviceSourceCatalog;
use App\Support\DeviceSourceKey;
use App\Support\IntegrationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DeviceSourceRecordTest extends TestCase
{
    use RefreshDatabase;

    public function test_reconcile_stores_source_record_linked_to_resolved_asset(): void
    {
        [$tenant, $organization, $integration] = $this->fixtures();

        $result = app(AssetReconciliationService::class)->reconcile(
            $this->snapshot($tenant, $organization, $integration, 'endpoint-1', 'SN-1', 'pc-01'),
        );

        $record = AssetSourceRecord::query()->withoutGlobalScopes()->sole();
        $this->assertSame($result->asset->id, $record->asset_id);
        $this->assertSame('pc-01', $record->hostname);
        $this->assertNotNull($record->first_seen_at);
        $this->assertNotNull($record->last_reported_at);
    }

    public function test_ambiguous_snapshot_keeps_source_record_unlinked(): void
    {
        [$tenant, $organization, $integration] = $this->fixtures();
        $assetType = AssetType::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Computer']);

        Asset::factory()->count(2)->create([
            'tenant_id' => $tenant->id,
            'organization_id' => $organization->id,
            'asset_type_id' => $assetType->id,
            'serial_number' => 'SN-DUP',
            'primary_hostname' => 'pc-dup',
        ]);

        $result = app(AssetReconciliationService::class)->reconcile(
            $this->snapshot($tenant, $organization, $integration, 'endpoint-dup', 'SN-DUP', 'pc-dup'),
        );

        $this->assertTrue($result->ambiguous);
        $this->assertNull(AssetSourceRecord::query()->withoutGlobalScopes()->sole()->asset_id);
    }

    public function test_mark_missing_as_gone_only_touches_records_not_reported_since_sync_start(): void
    {
        [$tenant, $organization, $integration] = $this->fixtures();
        $service = app(AssetReconciliationService::class);

        $service->reconcile($this->snapshot($tenant, $organization, $integration, 'endpoint-old', 'SN-OLD', 'pc-old'));

        $this->travel(5)->minutes();
        $syncStartedAt = now();
        $service->reconcile($this->snapshot($tenant, $organization, $integration, 'endpoint-new', 'SN-NEW', 'pc-new'));

        $marked = app(DeviceSourceRecordService::class)->markMissingAsGone(
            $tenant->id,
            (string) $integration->id,
            DeviceSourceKey::ACTION1,
            $syncStartedAt,
        );

        $this->assertSame(1, $marked);
        $records = AssetSourceRecord::query()->withoutGlobalScopes()->get()->keyBy('provider_record_id');
        $this->assertNotNull($records['endpoint-old']->gone_at);
        $this->assertNull($records['endpoint-new']->gone_at);

        $this->travel(5)->minutes();
        $service->reconcile($this->snapshot($tenant, $organization, $integration, 'endpoint-old', 'SN-OLD', 'pc-old'));
        $this->assertNull($records['endpoint-old']->fresh()->gone_at);
    }

    public function test_status_follows_activity_enabled_flag_and_gone(): void
    {
        $now = Carbon::parse('2026-11-22 12:00:00');
        $this->travelTo($now);

        $record = new AssetSourceRecord(['last_activity_at' => $now->copy()->subDays(10)]);
        $this->assertSame(AssetSourceRecord::STATUS_ACTIVE, $record->status());
        $this->assertSame(AssetSourceRecord::STATUS_STALE, $record->status(thresholdDays: 7));

        $record->last_activity_at = $now->copy()->subDays(31);
        $this->assertSame(AssetSourceRecord::STATUS_STALE, $record->status());

        $record->last_activity_at = null;
        $this->assertSame(AssetSourceRecord::STATUS_STALE, $record->status());

        $record->last_activity_at = $now;
        $record->enabled = false;
        $this->assertSame(AssetSourceRecord::STATUS_DISABLED, $record->status());

        $record->gone_at = $now;
        $this->assertSame(AssetSourceRecord::STATUS_GONE, $record->status());
    }

    public function test_device_class_is_guessed_from_operating_system(): void
    {
        $this->assertSame(DeviceClass::Server, DeviceClass::fromOperatingSystem('Windows Server 2022 Standard'));
        $this->assertSame(DeviceClass::Workstation, DeviceClass::fromOperatingSystem('Windows 11 Enterprise'));
        $this->assertSame(DeviceClass::Workstation, DeviceClass::fromOperatingSystem('macOS 14.5'));
        $this->assertSame(DeviceClass::Mobile, DeviceClass::fromOperatingSystem('iOS'));
        $this->assertSame(DeviceClass::Mobile, DeviceClass::fromOperatingSystem('Android'));
        $this->assertSame(DeviceClass::Other, DeviceClass::fromOperatingSystem(null));
    }

    public function test_activity_time_parser_ignores_never_placeholders(): void
    {
        $this->assertNull(DeviceSourceRecordData::parseActivityTime('0001-01-01T00:00:00Z'));
        $this->assertNull(DeviceSourceRecordData::parseActivityTime(''));
        $this->assertNull(DeviceSourceRecordData::parseActivityTime('not a date'));
        $this->assertSame('2026-11-20', DeviceSourceRecordData::parseActivityTime('2026-11-20T08:30:00Z')->format('Y-m-d'));
    }

    public function test_catalog_lists_registered_sources_by_role_and_integration_scope(): void
    {
        $this->assertContains(DeviceSourceKey::MICROSOFT_INTUNE, DeviceSourceCatalog::withRole(DeviceSourceRole::DeviceManagement));
        $this->assertContains(DeviceSourceKey::ACTION1, DeviceSourceCatalog::withRole(DeviceSourceRole::RemoteManagement));

        [, , $integration] = $this->fixtures();

        $integration->config = ['scopes' => ['endpoints' => true]];
        $this->assertSame([DeviceSourceKey::ACTION1], DeviceSourceCatalog::enabledFor($integration));

        $integration->config = ['scopes' => ['endpoints' => false]];
        $this->assertSame([], DeviceSourceCatalog::enabledFor($integration));

        $integration->config = ['scopes' => ['endpoints' => true]];
        $integration->enabled = false;
        $this->assertSame([], DeviceSourceCatalog::enabledFor($integration));
    }

    /**
     * @return array{Tenant, Organization, OrganizationIntegration}
     */
    private function fixtures(): array
    {
        $tenant = Tenant::factory()->create();
        $organization = Organization::factory()->create(['tenant_id' => $tenant->id]);
        $integration = OrganizationIntegration::factory()->create([
            'tenant_id' => $tenant->id,
            'organization_id' => $organization->id,
            'type' => IntegrationType::ACTION1,
            'enabled' => true,
        ]);

        return [$tenant, $organization, $integration];
    }

    private function snapshot(Tenant $tenant, Organization $organization, OrganizationIntegration $integration, string $endpointId, string $serial, string $hostname): AssetProviderSnapshot
    {
        return new AssetProviderSnapshot(
            tenantId: $tenant->id,
            organizationId: $organization->id,
            organizationIntegrationId: (string) $integration->id,
            provider: IntegrationType::ACTION1,
            identifiers: [
                ['type' => AssetExternalIdentifierType::Action1EndpointId, 'value' => $endpointId],
                ['type' => AssetExternalIdentifierType::SerialNumber, 'value' => $serial],
                ['type' => AssetExternalIdentifierType::Hostname, 'value' => $hostname],
            ],
            attributes: [
                'name' => $hostname,
                'serial_number' => $serial,
                'primary_hostname' => $hostname,
            ],
            sourceRecord: new DeviceSourceRecordData(
                source: DeviceSourceKey::ACTION1,
                providerRecordId: $endpointId,
                displayName: $hostname,
                hostname: $hostname,
                serialNumber: $serial,
                operatingSystem: 'Windows 11',
                lastActivityAt: now(),
            ),
        );
    }
}
