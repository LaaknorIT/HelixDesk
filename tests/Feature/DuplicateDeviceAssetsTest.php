<?php

namespace Tests\Feature;

use App\Data\AssetProviderSnapshot;
use App\Data\DeviceSourceRecordData;
use App\Enums\AssetExternalIdentifierType;
use App\Enums\DeviceClass;
use App\Filament\Agent\Resources\Assets\Pages\ViewAsset;
use App\Models\Asset;
use App\Models\AssetAttributeProvenance;
use App\Models\AssetSourceRecord;
use App\Models\AssetType;
use App\Models\Document;
use App\Models\Organization;
use App\Models\OrganizationIntegration;
use App\Models\Tenant;
use App\Models\User;
use App\Repositories\OrganizationIntegrationRepository;
use App\Services\ActiveDirectory\ActiveDirectoryInventoryService;
use App\Services\AssetMergeService;
use App\Services\AssetReconciliationService;
use App\Services\DeviceCoverageService;
use App\Services\DeviceSourceRecordService;
use App\Support\DeviceSourceKey;
use App\Support\IntegrationType;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A computer found by Active Directory and by Intune (or the RMM) must become one asset, whichever source sees it
 * first, and assets that were already created twice can be merged.
 */
class DuplicateDeviceAssetsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Organization $organization;

    private OrganizationIntegration $activeDirectory;

    private OrganizationIntegration $entra;

    private int $assetTypeId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-24 10:00:00'));

        $this->tenant = Tenant::factory()->create();
        $this->organization = Organization::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'ScanSense AS']);
        $this->activeDirectory = app(OrganizationIntegrationRepository::class)->createActiveDirectoryFromForm($this->organization, [
            'ad_domain_fqdn' => 'scansense.local',
            'ad_search_bases' => 'DC=scansense,DC=local',
            'ad_excluded_ous' => '',
            'ad_active_threshold_days' => 30,
            'ad_create_assets' => 'active_only',
            'ad_sync_interval_hours' => 4,
            'ad_enabled' => true,
        ]);
        $this->entra = OrganizationIntegration::factory()->create([
            'tenant_id' => $this->tenant->id,
            'organization_id' => $this->organization->id,
            'type' => IntegrationType::MICROSOFT_ENTRA,
            'enabled' => true,
            'config' => ['scopes' => ['computers' => true]],
        ]);
        $this->assetTypeId = (int) AssetType::query()->withoutGlobalScopes()
            ->firstOrCreate(['tenant_id' => $this->tenant->id, 'name' => 'Computer'])->getKey();
    }

    public function test_intune_links_to_the_computer_that_active_directory_created(): void
    {
        $this->syncActiveDirectory('APP01', 'Windows Server 2012 R2 Standard');
        $this->syncIntune('APP01', 'intune-app01', 'SN-APP01');

        $asset = Asset::query()->withoutGlobalScopes()->sole();
        $this->assertSame('SN-APP01', $asset->serial_number);
        $this->assertSame(2, AssetSourceRecord::query()->withoutGlobalScopes()->where('asset_id', $asset->id)->count());
    }

    public function test_active_directory_links_to_the_computer_that_intune_created(): void
    {
        $this->syncIntune('APP02', 'intune-app02', 'SN-APP02');
        $this->syncActiveDirectory('APP02', 'Windows Server 2012 R2 Standard');

        $this->assertSame(1, Asset::query()->withoutGlobalScopes()->count());
    }

    public function test_a_name_never_links_a_device_with_another_serial_number_or_device_id(): void
    {
        $this->syncIntune('PC-01', 'intune-a', 'SN-A');

        // Same name, another Intune device: a reinstalled or renamed PC is a different device to Intune.
        $this->syncIntune('PC-01', 'intune-b', 'SN-B');
        $this->assertSame(2, Asset::query()->withoutGlobalScopes()->count());

        // With two candidates, Active Directory links neither.
        $this->syncActiveDirectory('PC-01', 'Windows 11 Enterprise');
        $this->assertSame(2, Asset::query()->withoutGlobalScopes()->count());
        $this->assertNull(AssetSourceRecord::query()->withoutGlobalScopes()->where('source', DeviceSourceKey::ACTIVE_DIRECTORY)->sole()->asset_id);
    }

    public function test_a_second_ad_object_with_the_same_name_is_not_linked_to_the_first(): void
    {
        $this->syncActiveDirectory('PC-02', 'Windows 11 Enterprise', guid: 'aaaaaaaa-0000-0000-0000-000000000001');
        $this->syncActiveDirectory('PC-02', 'Windows 11 Enterprise', guid: 'aaaaaaaa-0000-0000-0000-000000000002');

        $this->assertSame(2, Asset::query()->withoutGlobalScopes()->count());
    }

    public function test_a_server_that_intune_reports_as_windows_is_shown_as_a_server(): void
    {
        $this->syncIntune('APP03', 'intune-app03', 'SN-APP03', operatingSystem: 'Windows');
        $this->syncActiveDirectory('APP03', 'Windows Server 2019 Datacenter');

        $row = app(DeviceCoverageService::class)->build($this->tenant->id)->rows[0];

        $this->assertSame(DeviceClass::Server->value, $row['device_class']);
        $this->assertStringContainsString('Server 2019', (string) $row['operating_system']);
    }

    public function test_existing_duplicates_are_merged_into_one_asset(): void
    {
        [$adAsset, $intuneAsset] = $this->existingDuplicatePair('APP01');

        $document = Document::factory()->create(['tenant_id' => $this->tenant->id]);
        $adAsset->documents()->attach($document->id, ['tenant_id' => $this->tenant->id]);
        DB::table('asset_software_installations')->insert([
            ['tenant_id' => $this->tenant->id, 'asset_id' => $adAsset->id, 'organization_integration_id' => $this->entra->id, 'provider' => 'microsoft_entra', 'external_id' => 'app-1', 'name' => 'App 1', 'source' => 'discovered', 'created_at' => now(), 'updated_at' => now()],
            ['tenant_id' => $this->tenant->id, 'asset_id' => $intuneAsset->id, 'organization_integration_id' => $this->entra->id, 'provider' => 'microsoft_entra', 'external_id' => 'app-1', 'name' => 'App 1', 'source' => 'discovered', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $service = app(AssetMergeService::class);
        $groups = $service->findDuplicateGroups($this->tenant->id);
        $this->assertCount(1, $groups);
        $this->assertSame($intuneAsset->id, $groups[0][0]->id, 'The asset with a serial number is kept.');

        $results = $service->mergeDuplicates($this->tenant->id);

        $this->assertSame([$adAsset->id], $results[0]['merged']);
        $this->assertSame([$intuneAsset->id], Asset::query()->withoutGlobalScopes()->pluck('id')->all());
        $this->assertSame(2, AssetSourceRecord::query()->withoutGlobalScopes()->where('asset_id', $intuneAsset->id)->count());
        $this->assertDatabaseHas('asset_external_identifiers', [
            'asset_id' => $intuneAsset->id,
            'identifier_type' => AssetExternalIdentifierType::AdObjectGuid->value,
        ]);
        $this->assertDatabaseHas('document_links', ['document_id' => $document->id, 'linkable_id' => $intuneAsset->id]);
        $this->assertSame(1, DB::table('asset_software_installations')->where('asset_id', $intuneAsset->id)->count());
        $this->assertSame(0, AssetAttributeProvenance::query()->withoutGlobalScopes()->where('asset_id', $adAsset->id)->count());

        // The next syncs keep using the merged asset.
        $this->syncActiveDirectory('APP01', 'Windows Server 2012 R2 Standard');
        $this->syncIntune('APP01', 'intune-app01', 'SN-APP01');
        $this->assertSame(1, Asset::query()->withoutGlobalScopes()->count());

        $row = app(DeviceCoverageService::class)->build($this->tenant->id)->rows[0];
        $this->assertSame(DeviceClass::Server->value, $row['device_class']);
    }

    public function test_different_devices_and_hand_made_assets_are_not_merged(): void
    {
        // Two Intune devices with the same name.
        $this->syncIntune('PC-03', 'intune-a', 'SN-A');
        $this->syncIntune('PC-03', 'intune-b', 'SN-B');

        // Two assets created by hand with the same name.
        Asset::factory()->count(2)->create([
            'tenant_id' => $this->tenant->id,
            'organization_id' => $this->organization->id,
            'asset_type_id' => $this->assetTypeId,
            'name' => 'Printer',
            'primary_hostname' => null,
        ]);

        // The same name under another customer.
        $other = Organization::factory()->create(['tenant_id' => $this->tenant->id]);
        Asset::query()->withoutGlobalScopes()->where('name', 'PC-03')->first()->update(['organization_id' => $other->id]);

        $this->assertSame([], app(AssetMergeService::class)->findDuplicateGroups($this->tenant->id));
    }

    public function test_merge_command_lists_with_dry_run_and_merges_without(): void
    {
        $this->existingDuplicatePair('APP02');

        $this->artisan('helixdesk:merge-duplicate-assets', ['--dry-run' => true])
            ->expectsOutputToContain('Found 1 duplicate asset(s)')
            ->assertSuccessful();
        $this->assertSame(2, Asset::query()->withoutGlobalScopes()->count());

        $this->artisan('helixdesk:merge-duplicate-assets', ['--tenant' => $this->tenant->id])
            ->expectsOutputToContain('Merged 1 duplicate asset(s)')
            ->assertSuccessful();
        $this->assertSame(1, Asset::query()->withoutGlobalScopes()->count());
    }

    public function test_an_agent_merges_a_duplicate_from_the_asset_page(): void
    {
        [$adAsset, $intuneAsset] = $this->existingDuplicatePair('APP01');
        $this->actingAsTenantAdmin();

        Livewire::test(ViewAsset::class, ['record' => $adAsset->getRouteKey()])
            ->callAction('mergeDuplicate', ['duplicate_asset_id' => $intuneAsset->id])
            ->assertHasNoActionErrors();

        $this->assertSame([$adAsset->id], Asset::query()->withoutGlobalScopes()->pluck('id')->all());
        $this->assertSame('SN-APP01', $adAsset->fresh()->serial_number);
    }

    public function test_the_asset_page_refuses_to_merge_different_devices(): void
    {
        $this->syncIntune('PC-04', 'intune-a', 'SN-A');
        $this->syncIntune('PC-04', 'intune-b', 'SN-B');
        [$first, $second] = Asset::query()->withoutGlobalScopes()->orderBy('id')->get()->all();
        $this->actingAsTenantAdmin();

        Livewire::test(ViewAsset::class, ['record' => $first->getRouteKey()])
            ->callAction('mergeDuplicate', ['duplicate_asset_id' => $second->id])
            ->assertNotified();

        $this->assertSame(2, Asset::query()->withoutGlobalScopes()->count());
    }

    /**
     * Two assets for one computer, as the sync created them before the fix: Active Directory and Intune each made
     * their own asset.
     *
     * @return array{0: Asset, 1: Asset}
     */
    private function existingDuplicatePair(string $name): array
    {
        $reconciliation = app(AssetReconciliationService::class);

        $adSnapshot = $this->activeDirectorySnapshot($name, 'Windows Server 2012 R2 Standard', 'bbbbbbbb-0000-0000-0000-'.str_pad(substr(md5($name), 0, 12), 12, '0'));
        $adAsset = $reconciliation->reconcile($adSnapshot)->asset;

        $intuneAsset = $reconciliation->createAssetFromSnapshot($this->intuneSnapshot($name, 'intune-'.strtolower($name), 'SN-'.$name, 'Windows'));
        app(DeviceSourceRecordService::class)->record($this->intuneSnapshot($name, 'intune-'.strtolower($name), 'SN-'.$name, 'Windows'), $intuneAsset);

        $this->assertNotSame($adAsset->id, $intuneAsset->id);

        return [$adAsset, $intuneAsset];
    }

    private function syncActiveDirectory(string $name, string $operatingSystem, ?string $guid = null): void
    {
        app(AssetReconciliationService::class)->reconcile(
            $this->activeDirectorySnapshot($name, $operatingSystem, $guid ?? 'cccccccc-0000-0000-0000-'.str_pad(substr(md5($name), 0, 12), 12, '0')),
        );
    }

    private function activeDirectorySnapshot(string $name, string $operatingSystem, string $guid): AssetProviderSnapshot
    {
        return app(ActiveDirectoryInventoryService::class)->snapshot($this->activeDirectory, $this->assetTypeId, [
            'object_guid' => $guid,
            'object_sid' => 'S-1-5-21-1000-2000-3000-'.substr($guid, -4),
            'name' => $name,
            'dns_host_name' => strtolower($name).'.scansense.local',
            'operating_system' => $operatingSystem,
            'operating_system_version' => '6.3 (9600)',
            'enabled' => true,
            'last_logon_timestamp' => now()->subDay()->toIso8601String(),
            'pwd_last_set' => now()->subDays(5)->toIso8601String(),
        ]);
    }

    private function syncIntune(string $name, string $managedDeviceId, string $serial, string $operatingSystem = 'Windows'): void
    {
        app(AssetReconciliationService::class)->reconcile($this->intuneSnapshot($name, $managedDeviceId, $serial, $operatingSystem));
    }

    /**
     * The snapshot MicrosoftEntraOrganizationSyncService builds for an Intune managed device.
     */
    private function intuneSnapshot(string $name, string $managedDeviceId, string $serial, string $operatingSystem): AssetProviderSnapshot
    {
        return new AssetProviderSnapshot(
            tenantId: $this->tenant->id,
            organizationId: $this->organization->id,
            organizationIntegrationId: (string) $this->entra->id,
            provider: IntegrationType::MICROSOFT_ENTRA,
            identifiers: [
                ['type' => AssetExternalIdentifierType::EntraIntuneManagedDeviceId, 'value' => $managedDeviceId],
                ['type' => AssetExternalIdentifierType::Hostname, 'value' => $name],
                ['type' => AssetExternalIdentifierType::SerialNumber, 'value' => $serial],
            ],
            attributes: [
                'name' => $name,
                'serial_number' => $serial,
                'primary_hostname' => $name,
                'operating_system' => $operatingSystem,
                'lifecycle_status' => 'in_use',
                'asset_type_id' => $this->assetTypeId,
            ],
            sourceRecord: new DeviceSourceRecordData(
                source: DeviceSourceKey::MICROSOFT_INTUNE,
                providerRecordId: $managedDeviceId,
                displayName: $name,
                hostname: $name,
                serialNumber: $serial,
                operatingSystem: $operatingSystem,
                lastActivityAt: now()->subHour(),
            ),
            matchByUniqueHostname: true,
        );
    }

    private function actingAsTenantAdmin(): void
    {
        $admin = User::factory()->create(['tenant_id' => $this->tenant->id, 'type' => 'agent']);
        setPermissionsTeamId($this->tenant->id);
        $admin->assignRole('Tenant Admin');
        $this->actingAs($admin);
        Filament::setCurrentPanel('agent');
        Filament::setTenant($this->tenant, isQuiet: true);
    }
}
