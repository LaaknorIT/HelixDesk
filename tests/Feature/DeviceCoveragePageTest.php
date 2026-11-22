<?php

namespace Tests\Feature;

use App\Enums\DeviceClass;
use App\Filament\Agent\Pages\DeviceCoverage;
use App\Models\Asset;
use App\Models\AssetSourceRecord;
use App\Models\AssetType;
use App\Models\Organization;
use App\Models\OrganizationIntegration;
use App\Models\Tenant;
use App\Models\User;
use App\Support\DeviceSourceKey;
use App\Support\IntegrationHealth\IntegrationHealthInspector;
use App\Support\IntegrationType;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DeviceCoveragePageTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Organization $organization;

    private OrganizationIntegration $entra;

    private OrganizationIntegration $action1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->organization = Organization::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Acme']);
        $this->entra = $this->integration(IntegrationType::MICROSOFT_ENTRA, ['computers' => true]);
        $this->action1 = $this->integration(IntegrationType::ACTION1, ['endpoints' => true]);

        $intuneOnly = $this->asset('PC-NO-RMM');
        $this->record($this->entra, DeviceSourceKey::MICROSOFT_INTUNE, 'intune-1', $intuneOnly);

        $both = $this->asset('PC-COVERED');
        $this->record($this->entra, DeviceSourceKey::MICROSOFT_INTUNE, 'intune-2', $both);
        $this->record($this->action1, DeviceSourceKey::ACTION1, 'a1-2', $both);
    }

    public function test_page_shows_a_column_per_source_and_the_missing_device(): void
    {
        $this->actAsAgent('Tenant Admin');

        Livewire::test(DeviceCoverage::class)
            ->assertOk()
            ->assertSee('Intune')
            ->assertSee('Action1')
            ->assertSee('PC-NO-RMM')
            ->assertSee('PC-COVERED')
            ->assertSee('Device management → remote management')
            ->assertSee('Missing');
    }

    public function test_only_findings_filter_hides_covered_devices(): void
    {
        $this->actAsAgent('Tenant Admin');

        Livewire::test(DeviceCoverage::class)
            ->filterTable('only_findings', true)
            ->assertSee('PC-NO-RMM')
            ->assertDontSee('PC-COVERED');
    }

    public function test_csv_export_lists_devices_and_findings(): void
    {
        $this->actAsAgent('Tenant Admin');

        Livewire::test(DeviceCoverage::class)
            ->callAction('export')
            ->assertFileDownloaded('device-coverage-'.now()->format('Y-m-d').'.csv');
    }

    public function test_api_returns_sources_rules_and_devices(): void
    {
        $this->actAsAgent('Agent');

        $this->getJson('/api/v1/device-coverage?only_findings=1')
            ->assertOk()
            ->assertJsonPath('data.sources.0.key', DeviceSourceKey::MICROSOFT_INTUNE)
            ->assertJsonPath('data.sources.1.key', DeviceSourceKey::ACTION1)
            ->assertJsonCount(1, 'data.devices')
            ->assertJsonPath('data.devices.0.name', 'PC-NO-RMM')
            ->assertJsonPath('data.devices.0.findings.0', 'device_management_to_remote_management');

        $other = Organization::factory()->create();
        $this->getJson('/api/v1/device-coverage?organization_id='.$other->id)->assertNotFound();
    }

    public function test_integration_that_should_see_the_device_gets_a_coverage_warning(): void
    {
        $action1Issues = collect(IntegrationHealthInspector::inspect($this->action1->fresh())->issues)->where('code', 'coverage_gap');
        $entraIssues = collect(IntegrationHealthInspector::inspect($this->entra->fresh())->issues)->where('code', 'coverage_gap');

        $this->assertCount(1, $action1Issues);
        $this->assertStringContainsString('1 active device is missing here', $action1Issues->first()->title);
        $this->assertCount(0, $entraIssues);
    }

    private function actAsAgent(string $role): User
    {
        $agent = User::factory()->create(['tenant_id' => $this->tenant->id, 'type' => 'agent']);

        setPermissionsTeamId($this->tenant->id);
        $agent->assignRole($role);

        $this->actingAs($agent);
        Filament::setCurrentPanel('agent');
        Filament::setTenant($this->tenant, isQuiet: true);

        return $agent;
    }

    /**
     * @param  array<string, bool>  $scopes
     */
    private function integration(string $type, array $scopes): OrganizationIntegration
    {
        return OrganizationIntegration::factory()->create([
            'tenant_id' => $this->tenant->id,
            'organization_id' => $this->organization->id,
            'type' => $type,
            'enabled' => true,
            'config' => ['scopes' => $scopes],
            'last_sync_at' => now(),
        ]);
    }

    private function asset(string $name): Asset
    {
        $assetType = AssetType::query()->withoutGlobalScopes()->firstOrCreate(['tenant_id' => $this->tenant->id, 'name' => 'Computer']);

        return Asset::factory()->create([
            'tenant_id' => $this->tenant->id,
            'organization_id' => $this->organization->id,
            'asset_type_id' => $assetType->id,
            'name' => $name,
            'primary_hostname' => strtolower($name),
            'operating_system' => 'Windows 11 Enterprise',
        ]);
    }

    private function record(OrganizationIntegration $integration, string $source, string $id, Asset $asset): void
    {
        AssetSourceRecord::query()->create([
            'tenant_id' => $integration->tenant_id,
            'organization_id' => $integration->organization_id,
            'organization_integration_id' => $integration->id,
            'source' => $source,
            'provider_record_id' => $id,
            'asset_id' => $asset->id,
            'display_name' => $asset->name,
            'hostname' => $asset->primary_hostname,
            'operating_system' => $asset->operating_system,
            'device_class' => DeviceClass::Workstation,
            'last_activity_at' => now()->subDay(),
            'first_seen_at' => now(),
            'last_reported_at' => now(),
        ]);
    }
}
