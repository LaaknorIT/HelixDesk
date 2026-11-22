<?php

namespace Tests\Feature;

use App\Enums\DeviceClass;
use App\Models\Asset;
use App\Models\AssetSourceRecord;
use App\Models\AssetType;
use App\Models\Organization;
use App\Models\OrganizationIntegration;
use App\Models\Tenant;
use App\Services\DeviceCoverageService;
use App\Support\DeviceCoverage\DeviceCoverageReport;
use App\Support\DeviceSourceKey;
use App\Support\IntegrationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DeviceCoverageServiceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Organization $organization;

    private OrganizationIntegration $entra;

    private OrganizationIntegration $action1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-22 12:00:00'));

        $this->tenant = Tenant::factory()->create();
        $this->organization = Organization::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Acme']);
        $this->entra = OrganizationIntegration::factory()->create([
            'tenant_id' => $this->tenant->id,
            'organization_id' => $this->organization->id,
            'type' => IntegrationType::MICROSOFT_ENTRA,
            'enabled' => true,
            'config' => ['scopes' => ['computers' => true]],
        ]);
        $this->action1 = OrganizationIntegration::factory()->create([
            'tenant_id' => $this->tenant->id,
            'organization_id' => $this->organization->id,
            'type' => IntegrationType::ACTION1,
            'enabled' => true,
            'config' => ['scopes' => ['endpoints' => true]],
        ]);
    }

    public function test_device_in_intune_without_action1_is_a_finding_and_the_reverse_too(): void
    {
        $intuneOnly = $this->asset('PC-INTUNE');
        $this->record($this->entra, DeviceSourceKey::MICROSOFT_INTUNE, 'intune-1', $intuneOnly, 'pc-intune', now()->subDay());

        $action1Only = $this->asset('PC-RMM');
        $this->record($this->action1, DeviceSourceKey::ACTION1, 'a1-1', $action1Only, 'pc-rmm', now()->subDay());

        $both = $this->asset('PC-BOTH');
        $this->record($this->entra, DeviceSourceKey::MICROSOFT_INTUNE, 'intune-2', $both, 'pc-both', now()->subDay());
        $this->record($this->action1, DeviceSourceKey::ACTION1, 'a1-2', $both, 'pc-both', now()->subDay());

        $report = app(DeviceCoverageService::class)->build($this->tenant->id);

        $this->assertSame([DeviceSourceKey::MICROSOFT_INTUNE => 'Intune', DeviceSourceKey::ACTION1 => 'Action1'], $report->sources);

        $rows = collect($report->rows)->keyBy('name');
        $this->assertSame(['device_management_to_remote_management'], $rows['PC-INTUNE']['findings']);
        $this->assertSame(DeviceCoverageReport::CELL_MISSING, $rows['PC-INTUNE']['cells'][DeviceSourceKey::ACTION1]);
        $this->assertSame(['remote_management_to_device_management'], $rows['PC-RMM']['findings']);
        $this->assertSame(DeviceCoverageReport::CELL_MISSING, $rows['PC-RMM']['cells'][DeviceSourceKey::MICROSOFT_INTUNE]);
        $this->assertSame([], $rows['PC-BOTH']['findings']);

        $this->assertSame(1, $report->missingCount('device_management_to_remote_management'));
        $this->assertSame(1, $report->missingCount('remote_management_to_device_management'));
        $this->assertSame('PC-INTUNE', $report->rows[0]['name'], 'Rows with findings come first.');
    }

    public function test_stale_devices_and_servers_are_not_in_scope_of_workstation_rules(): void
    {
        $stale = $this->asset('PC-OLD');
        $this->record($this->entra, DeviceSourceKey::MICROSOFT_INTUNE, 'intune-old', $stale, 'pc-old', now()->subDays(45));

        $server = $this->asset('SRV-01', 'Windows Server 2022');
        $this->record($this->action1, DeviceSourceKey::ACTION1, 'a1-srv', $server, 'srv-01', now()->subDay(), 'Windows Server 2022');

        $report = app(DeviceCoverageService::class)->build($this->tenant->id);
        $rows = collect($report->rows)->keyBy('name');

        $this->assertSame(DeviceCoverageReport::CELL_STALE, $rows['PC-OLD']['cells'][DeviceSourceKey::MICROSOFT_INTUNE]);
        $this->assertSame([], $rows['PC-OLD']['findings']);
        $this->assertSame([], $rows['SRV-01']['findings']);
        $this->assertSame(DeviceCoverageReport::CELL_ABSENT, $rows['SRV-01']['cells'][DeviceSourceKey::MICROSOFT_INTUNE]);
    }

    public function test_active_threshold_comes_from_the_integration_config(): void
    {
        $this->entra->update(['config' => ['scopes' => ['computers' => true], 'active_threshold_days' => 60]]);

        $asset = $this->asset('PC-SLOW');
        $this->record($this->entra, DeviceSourceKey::MICROSOFT_INTUNE, 'intune-slow', $asset, 'pc-slow', now()->subDays(45));

        $row = app(DeviceCoverageService::class)->build($this->tenant->id)->rows[0];

        $this->assertSame(DeviceCoverageReport::CELL_ACTIVE, $row['cells'][DeviceSourceKey::MICROSOFT_INTUNE]);
        $this->assertSame(['device_management_to_remote_management'], $row['findings']);
    }

    public function test_unlinked_record_joins_the_asset_with_the_same_hostname_as_a_probable_match(): void
    {
        $asset = $this->asset('PC-42', primaryHostname: 'pc-42.corp.example');
        $this->record($this->entra, DeviceSourceKey::MICROSOFT_INTUNE, 'intune-42', $asset, 'pc-42', now()->subDay());
        $this->record($this->action1, DeviceSourceKey::ACTION1, 'a1-42', null, 'pc-42', now()->subDay());

        $report = app(DeviceCoverageService::class)->build($this->tenant->id);

        $this->assertCount(1, $report->rows);
        $this->assertTrue($report->rows[0]['probable_match']);
        $this->assertSame([], $report->rows[0]['findings']);
    }

    public function test_gone_records_and_disabled_integrations_are_left_out(): void
    {
        $asset = $this->asset('PC-GONE');
        $record = $this->record($this->entra, DeviceSourceKey::MICROSOFT_INTUNE, 'intune-gone', $asset, 'pc-gone', now()->subDay());
        $record->update(['gone_at' => now()]);

        $this->action1->update(['enabled' => false]);
        $other = $this->asset('PC-A1');
        $this->record($this->action1, DeviceSourceKey::ACTION1, 'a1-off', $other, 'pc-a1', now()->subDay());

        $report = app(DeviceCoverageService::class)->build($this->tenant->id);

        $this->assertSame([DeviceSourceKey::MICROSOFT_INTUNE => 'Intune'], $report->sources);
        $this->assertSame([], $report->rows);
        $this->assertSame([], $report->ruleSummaries, 'No rule applies with only one role enabled.');
    }

    public function test_report_is_scoped_to_tenant_and_organization(): void
    {
        $otherTenant = Tenant::factory()->create();
        $otherOrganization = Organization::factory()->create(['tenant_id' => $otherTenant->id]);
        $otherIntegration = OrganizationIntegration::factory()->create([
            'tenant_id' => $otherTenant->id,
            'organization_id' => $otherOrganization->id,
            'type' => IntegrationType::ACTION1,
            'enabled' => true,
            'config' => ['scopes' => ['endpoints' => true]],
        ]);
        $this->record($otherIntegration, DeviceSourceKey::ACTION1, 'other-1', null, 'other-pc', now()->subDay());

        $secondOrganization = Organization::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->asset('PC-MINE');
        $this->record($this->action1, DeviceSourceKey::ACTION1, 'mine-1', null, 'pc-mine', now()->subDay());

        $this->assertCount(1, app(DeviceCoverageService::class)->build($this->tenant->id)->rows);
        $this->assertCount(0, app(DeviceCoverageService::class)->build($this->tenant->id, $secondOrganization->id)->rows);
    }

    private function asset(string $name, string $operatingSystem = 'Windows 11 Enterprise', ?string $primaryHostname = null): Asset
    {
        $assetType = AssetType::query()->withoutGlobalScopes()->firstOrCreate(['tenant_id' => $this->tenant->id, 'name' => 'Computer']);

        return Asset::factory()->create([
            'tenant_id' => $this->tenant->id,
            'organization_id' => $this->organization->id,
            'asset_type_id' => $assetType->id,
            'name' => $name,
            'primary_hostname' => $primaryHostname ?? strtolower($name),
            'operating_system' => $operatingSystem,
        ]);
    }

    private function record(OrganizationIntegration $integration, string $source, string $id, ?Asset $asset, string $hostname, Carbon $lastActivity, string $operatingSystem = 'Windows 11 Enterprise'): AssetSourceRecord
    {
        return AssetSourceRecord::query()->create([
            'tenant_id' => $integration->tenant_id,
            'organization_id' => $integration->organization_id,
            'organization_integration_id' => $integration->id,
            'source' => $source,
            'provider_record_id' => $id,
            'asset_id' => $asset?->id,
            'display_name' => $asset?->name ?? strtoupper($hostname),
            'hostname' => $hostname,
            'operating_system' => $operatingSystem,
            'device_class' => DeviceClass::fromOperatingSystem($operatingSystem),
            'last_activity_at' => $lastActivity,
            'first_seen_at' => now(),
            'last_reported_at' => now(),
        ]);
    }
}
