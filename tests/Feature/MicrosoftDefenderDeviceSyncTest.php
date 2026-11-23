<?php

namespace Tests\Feature;

use App\Enums\AssetExternalIdentifierType;
use App\Models\Asset;
use App\Models\AssetExternalIdentifier;
use App\Models\AssetSourceRecord;
use App\Models\AssetType;
use App\Models\Organization;
use App\Models\OrganizationIntegration;
use App\Models\Tenant;
use App\Services\DeviceCoverageService;
use App\Services\MicrosoftEntra\MicrosoftEntraOrganizationSyncService;
use App\Support\DeviceCoverage\DeviceCoverageReport;
use App\Support\DeviceSourceCatalog;
use App\Support\DeviceSourceKey;
use App\Support\EntraSyncTarget;
use App\Support\IntegrationCatalog;
use App\Support\IntegrationHealth\IntegrationHealthInspector;
use App\Support\IntegrationType;
use App\Support\OrganizationIntegrationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class MicrosoftDefenderDeviceSyncTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array<string, mixed>> */
    private array $machines = [];

    /** @var list<array<string, mixed>> */
    private array $managedDevices = [];

    private ?int $defenderStatus = null;

    public function test_defender_is_its_own_endpoint_security_source_on_the_entra_integration(): void
    {
        $source = DeviceSourceCatalog::find(DeviceSourceKey::MICROSOFT_DEFENDER);

        $this->assertNotNull($source);
        $this->assertSame(IntegrationType::MICROSOFT_ENTRA, $source['integration_type']);
        $this->assertSame('defender_devices', $source['scope']);
        $this->assertArrayHasKey('defender_devices', IntegrationCatalog::find(IntegrationType::MICROSOFT_ENTRA)['capabilities']);

        $config = OrganizationIntegrationSettings::entraFormDataToConfig([
            'entra_enabled' => true,
            'entra_scope_defender_devices' => true,
        ]);
        $this->assertTrue($config['scopes']['defender_devices']);
        $this->assertTrue(OrganizationIntegrationSettings::entraFormHasSyncScope(['entra_scope_defender_devices' => true]));
        $this->assertTrue(OrganizationIntegrationSettings::entraConfigToFormFields($config)['entra_scope_defender_devices']);
    }

    public function test_sync_links_onboarded_devices_keeps_discovered_ones_unlinked_and_marks_removed_ones_gone(): void
    {
        $tenant = Tenant::factory()->create();
        $organization = Organization::factory()->create(['tenant_id' => $tenant->id]);
        $integration = $this->entraIntegration($tenant, $organization);
        $intuneAsset = $this->asset($tenant, $organization, 'LAPTOP-01', 'laptop-01');
        AssetExternalIdentifier::query()->create([
            'tenant_id' => $tenant->id,
            'organization_id' => $organization->id,
            'asset_id' => $intuneAsset->id,
            'organization_integration_id' => $integration->id,
            'provider' => IntegrationType::MICROSOFT_ENTRA,
            'identifier_type' => AssetExternalIdentifierType::EntraAzureAdDeviceId,
            'external_id' => 'aad-laptop-01',
            'is_primary' => true,
        ]);
        $server = $this->asset($tenant, $organization, 'SRV-FILE01', 'srv-file01.corp.example');

        $machines = [
            [
                'id' => 'mde-laptop-01',
                'computerDnsName' => 'laptop-01.corp.example',
                'aadDeviceId' => 'aad-laptop-01',
                'osPlatform' => 'Windows11',
                'version' => '23H2',
                'onboardingStatus' => 'Onboarded',
                'healthStatus' => 'Active',
                'lastSeen' => now()->subDay()->toIso8601String(),
                'riskScore' => 'Low',
            ],
            [
                'id' => 'mde-srv-file01',
                'computerDnsName' => 'srv-file01.corp.example',
                'osPlatform' => 'WindowsServer2022',
                'onboardingStatus' => 'Onboarded',
                'healthStatus' => 'Active',
                'lastSeen' => now()->subHours(2)->toIso8601String(),
            ],
            [
                'id' => 'mde-discovered-01',
                'computerDnsName' => 'laptop-02',
                'osPlatform' => 'Windows10',
                'onboardingStatus' => 'CanBeOnboarded',
                'lastSeen' => now()->subHours(5)->toIso8601String(),
            ],
        ];
        $this->fakeApis($machines);

        $sync = app(MicrosoftEntraOrganizationSyncService::class);
        $sync->sync($this->target($tenant, $organization, $integration));

        $records = AssetSourceRecord::query()->withoutGlobalScopes()
            ->where('source', DeviceSourceKey::MICROSOFT_DEFENDER)
            ->get()
            ->keyBy('provider_record_id');

        $this->assertCount(3, $records);
        $this->assertSame($intuneAsset->id, $records['mde-laptop-01']->asset_id, 'linked through the Entra device ID');
        $this->assertSame($server->id, $records['mde-srv-file01']->asset_id, 'linked through the unique computer name');
        $this->assertSame('server', $records['mde-srv-file01']->device_class->value);
        $this->assertSame('Windows Server 2022', $records['mde-srv-file01']->operating_system);
        $this->assertSame(AssetSourceRecord::STATUS_ACTIVE, $records['mde-laptop-01']->status());

        $discovered = $records['mde-discovered-01'];
        $this->assertNull($discovered->asset_id);
        $this->assertTrue($discovered->isDiscoveredOnly());
        $this->assertSame(AssetSourceRecord::STATUS_DISCOVERED, $discovered->status());
        $this->assertFalse(AssetSourceRecord::query()->withoutGlobalScopes()->active()->whereKey($discovered->id)->exists());
        $this->assertTrue(AssetSourceRecord::query()->withoutGlobalScopes()->active()->whereKey($records['mde-laptop-01']->id)->exists());

        $this->assertSame(2, Asset::query()->withoutGlobalScopes()->count(), 'Defender never creates assets');
        $this->assertSame('LAPTOP-01', $intuneAsset->fresh()->name, 'Defender does not change asset fields');
        $this->assertTrue(AssetExternalIdentifier::query()->withoutGlobalScopes()
            ->where('identifier_type', AssetExternalIdentifierType::DefenderMachineId)
            ->where('external_id', 'mde-srv-file01')
            ->where('asset_id', $server->id)
            ->exists());

        array_pop($this->machines);
        $this->travel(1)->minutes();
        $sync->sync($this->target($tenant, $organization, $integration));

        $this->assertNotNull($discovered->fresh()->gone_at);
        $this->assertNull($records['mde-laptop-01']->fresh()->gone_at);
    }

    public function test_coverage_report_flags_devices_that_defender_does_not_protect(): void
    {
        $tenant = Tenant::factory()->create();
        $organization = Organization::factory()->create(['tenant_id' => $tenant->id]);
        $integration = $this->entraIntegration($tenant, $organization, ['computers' => true, 'defender_devices' => true]);

        $this->fakeApis(
            machines: [[
                'id' => 'mde-laptop-02',
                'computerDnsName' => 'laptop-02',
                'osPlatform' => 'Windows11',
                'onboardingStatus' => 'CanBeOnboarded',
                'lastSeen' => now()->subHour()->toIso8601String(),
            ]],
            managedDevices: [
                [
                    'id' => 'intune-laptop-02',
                    'deviceName' => 'LAPTOP-02',
                    'serialNumber' => 'SN-02',
                    'azureADDeviceId' => 'aad-laptop-02',
                    'operatingSystem' => 'Windows',
                    'lastSyncDateTime' => now()->subHour()->toIso8601String(),
                ],
                [
                    'id' => 'intune-laptop-03',
                    'deviceName' => 'LAPTOP-03',
                    'serialNumber' => 'SN-03',
                    'azureADDeviceId' => 'aad-laptop-03',
                    'operatingSystem' => 'Windows',
                    'lastSyncDateTime' => now()->subHour()->toIso8601String(),
                ],
            ],
        );

        app(MicrosoftEntraOrganizationSyncService::class)->sync($this->target($tenant, $organization, $integration));

        $report = app(DeviceCoverageService::class)->build((int) $tenant->id);
        $this->assertArrayHasKey(DeviceSourceKey::MICROSOFT_INTUNE, $report->sources);
        $this->assertArrayHasKey(DeviceSourceKey::MICROSOFT_DEFENDER, $report->sources);

        $rows = collect($report->rows)->keyBy('name');
        $this->assertSame(DeviceCoverageReport::CELL_DISCOVERED, $rows['LAPTOP-02']['cells'][DeviceSourceKey::MICROSOFT_DEFENDER]);
        $this->assertContains('any_to_endpoint_security', $rows['LAPTOP-02']['findings']);
        $this->assertSame(DeviceCoverageReport::CELL_MISSING, $rows['LAPTOP-03']['cells'][DeviceSourceKey::MICROSOFT_DEFENDER]);

        $summary = collect($report->ruleSummaries)->firstWhere(fn (array $s): bool => $s['rule']->key === 'any_to_endpoint_security');
        $this->assertSame(2, $summary['missing']);
    }

    public function test_missing_defender_permission_skips_the_scope_and_shows_a_health_warning(): void
    {
        $tenant = Tenant::factory()->create();
        $organization = Organization::factory()->create(['tenant_id' => $tenant->id]);
        $integration = $this->entraIntegration($tenant, $organization, ['users' => true, 'defender_devices' => true]);

        Http::fake(function (Request $request) {
            $url = $request->url();
            if (str_contains($url, 'login.microsoftonline.com')) {
                $defender = str_contains((string) ($request->data()['scope'] ?? ''), 'securitycenter');

                return Http::response([
                    'access_token' => $this->jwt($defender ? ['Alert.Read.All'] : ['User.Read.All']),
                    'expires_in' => 3600,
                ], 200);
            }
            if (str_contains($url, 'api.security.microsoft.com')) {
                return Http::response(['error' => 'should not be called'], 500);
            }

            return Http::response(['value' => []], 200);
        });

        app(MicrosoftEntraOrganizationSyncService::class)->sync($this->target($tenant, $organization, $integration));

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'api.security.microsoft.com'));

        $integration->refresh();
        $this->assertNull($integration->config['last_entra_sync_error'] ?? null);

        $codes = array_map(fn ($issue): string => $issue->code, IntegrationHealthInspector::inspect($integration)->warnings());
        $this->assertContains('missing_permission:defender_devices', $codes);
        $this->assertNotContains('missing_permission:users', $codes);
    }

    public function test_defender_api_failure_is_a_scope_warning_and_removes_nothing(): void
    {
        $tenant = Tenant::factory()->create();
        $organization = Organization::factory()->create(['tenant_id' => $tenant->id]);
        $integration = $this->entraIntegration($tenant, $organization);

        $this->fakeApis([[
            'id' => 'mde-1',
            'computerDnsName' => 'pc-1',
            'osPlatform' => 'Windows11',
            'onboardingStatus' => 'Onboarded',
            'lastSeen' => now()->toIso8601String(),
        ]]);
        $sync = app(MicrosoftEntraOrganizationSyncService::class);
        $sync->sync($this->target($tenant, $organization, $integration));

        $this->defenderStatus = 403;
        $this->travel(1)->minutes();
        $sync->sync($this->target($tenant, $organization, $integration));

        $this->assertNull(AssetSourceRecord::query()->withoutGlobalScopes()->sole()->gone_at);
        $codes = array_map(fn ($issue): string => $issue->code, IntegrationHealthInspector::inspect($integration->fresh())->warnings());
        $this->assertContains('scope_warning:defender_devices', $codes);
    }

    /**
     * @param  list<array<string, mixed>>  $machines
     * @param  list<array<string, mixed>>  $managedDevices
     */
    private function fakeApis(array $machines, array $managedDevices = []): void
    {
        $this->machines = $machines;
        $this->managedDevices = $managedDevices;

        Http::fake(function (Request $request) {
            $url = $request->url();
            if (str_contains($url, 'login.microsoftonline.com')) {
                return Http::response(['access_token' => 'fake-token', 'expires_in' => 3600], 200);
            }
            if (str_contains($url, 'api.security.microsoft.com/api/machines')) {
                return $this->defenderStatus !== null
                    ? Http::response(['error' => ['code' => 'Forbidden']], $this->defenderStatus)
                    : Http::response(['value' => $this->machines], 200);
            }
            if (str_contains($url, 'graph.microsoft.com/v1.0/deviceManagement/managedDevices')) {
                return Http::response(['value' => $this->managedDevices], 200);
            }

            return Http::response(['value' => []], 200);
        });
    }

    /**
     * @param  list<string>  $roles
     */
    private function jwt(array $roles): string
    {
        $encode = fn (array $data): string => rtrim(strtr(base64_encode((string) json_encode($data)), '+/', '-_'), '=');

        return $encode(['alg' => 'none']).'.'.$encode(['roles' => $roles]).'.signature';
    }

    private function asset(Tenant $tenant, Organization $organization, string $name, string $hostname): Asset
    {
        $type = AssetType::query()->withoutGlobalScopes()->firstOrCreate(['tenant_id' => $tenant->id, 'name' => 'Computer']);

        return Asset::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'organization_id' => $organization->id,
            'asset_type_id' => $type->id,
            'name' => $name,
            'primary_hostname' => $hostname,
            'lifecycle_status' => 'in_use',
        ]);
    }

    /**
     * @param  array<string, bool>  $scopes
     */
    private function entraIntegration(Tenant $tenant, Organization $organization, array $scopes = ['defender_devices' => true]): OrganizationIntegration
    {
        return OrganizationIntegration::query()->create([
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
            'last_sync_at' => null,
            'last_sync_error' => null,
            'scope_key' => '',
        ]);
    }

    private function target(Tenant $tenant, Organization $organization, OrganizationIntegration $integration): EntraSyncTarget
    {
        $entry = OrganizationIntegrationSettings::findEntryForOrganization($organization, (string) $integration->getKey());

        return new EntraSyncTarget(
            tenantId: (int) $tenant->id,
            organizationId: (int) $organization->id,
            integrationId: (string) $integration->getKey(),
            config: is_array($entry['config'] ?? null) ? $entry['config'] : [],
        );
    }
}
