<?php

namespace Tests\Feature;

use App\Data\AssetProviderSnapshot;
use App\Enums\AssetExternalIdentifierType;
use App\Models\Asset;
use App\Models\AssetExternalIdentifier;
use App\Models\AssetSourceRecord;
use App\Models\AssetType;
use App\Models\Organization;
use App\Models\OrganizationIntegration;
use App\Models\Tenant;
use App\Services\AssetReconciliationService;
use App\Services\DeviceCoverageService;
use App\Services\MicrosoftEntra\MicrosoftEntraOrganizationSyncService;
use App\Support\DeviceCoverage\DeviceCoverageReport;
use App\Support\DeviceSourceCatalog;
use App\Support\DeviceSourceKey;
use App\Support\EntraSyncTarget;
use App\Support\IntegrationType;
use App\Support\OrganizationIntegrationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class MicrosoftEntraDeviceSyncTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array<string, mixed>> */
    private array $devices = [];

    public function test_entra_id_devices_are_a_directory_source_with_their_own_scope(): void
    {
        $source = DeviceSourceCatalog::find(DeviceSourceKey::MICROSOFT_ENTRA_DEVICE);

        $this->assertNotNull($source);
        $this->assertSame(IntegrationType::MICROSOFT_ENTRA, $source['integration_type']);
        $this->assertSame('devices', $source['scope']);

        $config = OrganizationIntegrationSettings::entraFormDataToConfig(['entra_scope_devices' => true]);
        $this->assertTrue($config['scopes']['devices']);
        $this->assertTrue(OrganizationIntegrationSettings::entraConfigToFormFields($config)['entra_scope_devices']);
        $this->assertTrue(OrganizationIntegrationSettings::entraFormHasSyncScope(['entra_scope_devices' => true]));
    }

    public function test_sync_links_through_the_device_id_keeps_registered_devices_as_seen_only_and_never_creates_assets(): void
    {
        [$tenant, $organization, $integration] = $this->setUpIntegration();
        $intuneAsset = $this->asset($tenant, $organization, 'PC-HYBRID-01');
        $this->identifier($intuneAsset, $integration, IntegrationType::MICROSOFT_ENTRA, AssetExternalIdentifierType::EntraAzureAdDeviceId, 'aad-hybrid-01');

        $this->devices = [
            $this->device('obj-hybrid-01', 'PC-HYBRID-01', 'ServerAd', deviceId: 'aad-hybrid-01', sid: 'S-1-5-21-1-2-3-1101'),
            $this->device('obj-joined-02', 'PC-JOINED-02', 'AzureAd', deviceId: 'aad-joined-02'),
            $this->device('obj-phone-03', 'iPhone', 'Workplace', deviceId: 'aad-phone-03', os: 'iOS'),
        ];
        $this->sync($tenant, $organization, $integration);

        $records = AssetSourceRecord::query()->withoutGlobalScopes()
            ->where('source', DeviceSourceKey::MICROSOFT_ENTRA_DEVICE)
            ->get()
            ->keyBy('provider_record_id');

        $this->assertCount(3, $records);
        $this->assertSame($intuneAsset->id, $records['obj-hybrid-01']->asset_id);
        $this->assertSame(AssetSourceRecord::STATUS_ACTIVE, $records['obj-hybrid-01']->status());
        $this->assertNull($records['obj-joined-02']->asset_id);
        $this->assertSame(AssetSourceRecord::STATUS_DISCOVERED, $records['obj-phone-03']->status());
        $this->assertSame('Registered only', DeviceSourceCatalog::discoveredLabel(DeviceSourceKey::MICROSOFT_ENTRA_DEVICE));
        $this->assertSame(1, Asset::query()->withoutGlobalScopes()->count());

        $this->assertTrue(AssetExternalIdentifier::query()->withoutGlobalScopes()
            ->where('asset_id', $intuneAsset->id)
            ->where('identifier_type', AssetExternalIdentifierType::AdObjectSid)
            ->where('external_id', 'S-1-5-21-1-2-3-1101')
            ->exists(), 'the hybrid device carries the on-premises SID to the asset');

        array_pop($this->devices);
        $this->travel(1)->minutes();
        $this->sync($tenant, $organization, $integration);

        $this->assertNotNull($records['obj-phone-03']->fresh()->gone_at);
        $this->assertNull($records['obj-hybrid-01']->fresh()->gone_at);
    }

    public function test_active_directory_computer_links_to_the_intune_asset_through_the_on_premises_sid(): void
    {
        [$tenant, $organization, $integration] = $this->setUpIntegration();
        $intuneAsset = $this->asset($tenant, $organization, 'LAPTOP-7F3K');
        $this->identifier($intuneAsset, $integration, IntegrationType::MICROSOFT_ENTRA, AssetExternalIdentifierType::EntraAzureAdDeviceId, 'aad-7f3k');

        $this->devices = [$this->device('obj-7f3k', 'LAPTOP-7F3K', 'ServerAd', deviceId: 'aad-7f3k', sid: 'S-1-5-21-9-9-9-4242')];
        $this->sync($tenant, $organization, $integration);

        // An AD computer whose name differs from the asset (renamed in AD), so only the SID can link it.
        $result = app(AssetReconciliationService::class)->reconcile(new AssetProviderSnapshot(
            tenantId: (int) $tenant->id,
            organizationId: (int) $organization->id,
            organizationIntegrationId: (string) $integration->id,
            provider: IntegrationType::ACTIVE_DIRECTORY,
            identifiers: [
                ['type' => AssetExternalIdentifierType::AdObjectGuid, 'value' => 'guid-7f3k'],
                ['type' => AssetExternalIdentifierType::AdObjectSid, 'value' => 'S-1-5-21-9-9-9-4242'],
            ],
            attributes: ['name' => 'OLD-NAME-01'],
            matchByUniqueHostname: true,
        ));

        $this->assertTrue($result->matched);
        $this->assertSame($intuneAsset->id, $result->asset?->id);
        $this->assertSame(1, Asset::query()->withoutGlobalScopes()->count());
    }

    public function test_coverage_report_flags_entra_joined_pcs_that_are_missing_in_intune(): void
    {
        [$tenant, $organization, $integration] = $this->setUpIntegration(['devices' => true, 'computers' => true]);

        $this->devices = [
            $this->device('obj-joined-02', 'PC-JOINED-02', 'AzureAd', deviceId: 'aad-joined-02'),
            $this->device('obj-byod-04', 'HOME-PC', 'Workplace', deviceId: 'aad-byod-04'),
        ];
        $this->sync($tenant, $organization, $integration);

        $report = app(DeviceCoverageService::class)->build((int) $tenant->id);
        $rows = collect($report->rows)->keyBy('name');

        $this->assertContains('directory_to_device_management', $rows['PC-JOINED-02']['findings']);
        $this->assertSame(DeviceCoverageReport::CELL_MISSING, $rows['PC-JOINED-02']['cells'][DeviceSourceKey::MICROSOFT_INTUNE]);
        $this->assertSame([], $rows['HOME-PC']['findings'], 'a registered personal device is not expected in Intune');
        $this->assertSame(DeviceCoverageReport::CELL_DISCOVERED, $rows['HOME-PC']['cells'][DeviceSourceKey::MICROSOFT_ENTRA_DEVICE]);
    }

    /**
     * @param  array<string, bool>  $scopes
     * @return array{0: Tenant, 1: Organization, 2: OrganizationIntegration}
     */
    private function setUpIntegration(array $scopes = ['devices' => true]): array
    {
        Http::fake(function (Request $request) {
            $url = $request->url();
            if (str_contains($url, 'login.microsoftonline.com')) {
                return Http::response(['access_token' => 'fake-token', 'expires_in' => 3600], 200);
            }
            if (str_contains($url, 'graph.microsoft.com/v1.0/devices')) {
                return Http::response(['value' => $this->devices], 200);
            }

            return Http::response(['value' => []], 200);
        });

        $tenant = Tenant::factory()->create();
        $organization = Organization::factory()->create(['tenant_id' => $tenant->id]);
        $integration = OrganizationIntegration::query()->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'organization_id' => $organization->id,
            'type' => OrganizationIntegrationSettings::TYPE_MICROSOFT_ENTRA,
            'enabled' => true,
            'config' => [
                'azure_tenant_id' => 'tenant-guid',
                'application_client_id' => 'client-guid',
                'scopes' => $scopes,
            ],
            'credentials' => ['client_secret' => 'secret'],
            'scope_key' => '',
        ]);

        return [$tenant, $organization, $integration];
    }

    private function sync(Tenant $tenant, Organization $organization, OrganizationIntegration $integration): void
    {
        $entry = OrganizationIntegrationSettings::findEntryForOrganization($organization, (string) $integration->getKey());

        app(MicrosoftEntraOrganizationSyncService::class)->sync(new EntraSyncTarget(
            tenantId: (int) $tenant->id,
            organizationId: (int) $organization->id,
            integrationId: (string) $integration->getKey(),
            config: is_array($entry['config'] ?? null) ? $entry['config'] : [],
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function device(string $id, string $name, string $trustType, string $deviceId, ?string $sid = null, string $os = 'Windows'): array
    {
        return [
            'id' => $id,
            'deviceId' => $deviceId,
            'displayName' => $name,
            'operatingSystem' => $os,
            'operatingSystemVersion' => '10.0.22631.4460',
            'trustType' => $trustType,
            'accountEnabled' => true,
            'approximateLastSignInDateTime' => now()->subDays(2)->toIso8601String(),
            'onPremisesSyncEnabled' => $sid !== null ? true : null,
            'onPremisesSecurityIdentifier' => $sid,
        ];
    }

    private function asset(Tenant $tenant, Organization $organization, string $name): Asset
    {
        $type = AssetType::query()->withoutGlobalScopes()->firstOrCreate(['tenant_id' => $tenant->id, 'name' => 'Computer']);

        return Asset::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'organization_id' => $organization->id,
            'asset_type_id' => $type->id,
            'name' => $name,
            'primary_hostname' => $name,
            'operating_system' => 'Windows',
            'lifecycle_status' => 'in_use',
        ]);
    }

    private function identifier(Asset $asset, OrganizationIntegration $integration, string $provider, AssetExternalIdentifierType $type, string $value): void
    {
        AssetExternalIdentifier::query()->create([
            'tenant_id' => $asset->tenant_id,
            'organization_id' => $asset->organization_id,
            'asset_id' => $asset->id,
            'organization_integration_id' => $integration->id,
            'provider' => $provider,
            'identifier_type' => $type,
            'external_id' => $value,
            'is_primary' => true,
        ]);
    }
}
