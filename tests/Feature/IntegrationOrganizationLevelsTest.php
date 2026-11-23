<?php

namespace Tests\Feature;

use App\Data\AssetProviderSnapshot;
use App\Data\AssetReconciliationResult;
use App\Data\DeviceSourceRecordData;
use App\Enums\AssetExternalIdentifierType;
use App\Filament\Agent\Pages\Integrations;
use App\Models\Asset;
use App\Models\AssetSourceRecord;
use App\Models\AssetType;
use App\Models\Organization;
use App\Models\OrganizationIntegration;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AssetReconciliationService;
use App\Services\DeviceCoverageService;
use App\Services\OrganizationIntegrationMover;
use App\Support\DeviceCoverage\DeviceCoverageReport;
use App\Support\DeviceSourceKey;
use App\Support\IntegrationType;
use App\Support\OrganizationLineage;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Integrations at different organization levels: an MSP-wide RMM high in the tree and a customer's own Microsoft 365
 * tenant lower down. A device lives in the most specific organization an integration has found it in.
 */
class IntegrationOrganizationLevelsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Organization $msp;

    private Organization $customerA;

    private Organization $customerB;

    private Organization $departmentA;

    private OrganizationIntegration $rmm;

    private OrganizationIntegration $entraA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->msp = Organization::factory()->group()->create(['tenant_id' => $this->tenant->id, 'name' => 'MSP']);
        $this->customerA = Organization::factory()->create(['tenant_id' => $this->tenant->id, 'parent_id' => $this->msp->id, 'name' => 'Customer A']);
        $this->customerB = Organization::factory()->create(['tenant_id' => $this->tenant->id, 'parent_id' => $this->msp->id, 'name' => 'Customer B']);
        $this->departmentA = Organization::factory()->department()->create(['tenant_id' => $this->tenant->id, 'parent_id' => $this->customerA->id, 'name' => 'Sales']);

        AssetType::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Computer']);

        $this->rmm = $this->integration($this->msp, IntegrationType::ACTION1);
        $this->entraA = $this->integration($this->customerA, IntegrationType::MICROSOFT_ENTRA);
    }

    public function test_device_found_first_by_higher_rmm_moves_down_when_customer_integration_finds_it(): void
    {
        $first = $this->reconcile($this->rmm, $this->rmmSnapshot('endpoint-1', 'entra-device-1'));

        $this->assertTrue($first->created);
        $this->assertSame($this->msp->id, (int) $first->asset->organization_id);

        $second = $this->reconcile($this->entraA, $this->entraSnapshot('entra-device-1'));

        $this->assertFalse($second->created);
        $this->assertSame($first->asset->id, $second->asset->id);
        $this->assertSame($this->customerA->id, (int) $first->asset->fresh()->organization_id);
        $this->assertSame(1, Asset::query()->withoutGlobalScopes()->count());

        $this->assertSame(
            [$this->customerA->id],
            AssetSourceRecord::query()->withoutGlobalScopes()->pluck('organization_id')->map(fn ($id): int => (int) $id)->unique()->values()->all(),
        );
    }

    public function test_higher_rmm_keeps_device_in_customer_after_the_move(): void
    {
        $this->reconcile($this->rmm, $this->rmmSnapshot('endpoint-1', 'entra-device-1'));
        $this->reconcile($this->entraA, $this->entraSnapshot('entra-device-1'));

        $again = $this->reconcile($this->rmm, $this->rmmSnapshot('endpoint-1', 'entra-device-1'));

        $this->assertFalse($again->created);
        $this->assertSame($this->customerA->id, (int) $again->asset->fresh()->organization_id);
        $this->assertSame($this->customerA->id, (int) AssetSourceRecord::query()->withoutGlobalScopes()
            ->where('organization_integration_id', $this->rmm->id)->value('organization_id'));
    }

    public function test_higher_rmm_links_device_already_found_by_customer_integration(): void
    {
        $first = $this->reconcile($this->entraA, $this->entraSnapshot('entra-device-1'));
        $second = $this->reconcile($this->rmm, $this->rmmSnapshot('endpoint-1', 'entra-device-1'));

        $this->assertSame($first->asset->id, $second->asset->id);
        $this->assertSame($this->customerA->id, (int) $second->asset->fresh()->organization_id);
        $this->assertSame(1, Asset::query()->withoutGlobalScopes()->count());
    }

    public function test_device_in_a_department_is_not_moved_up_by_the_customer_integration(): void
    {
        $asset = $this->reconcile($this->entraA, $this->entraSnapshot('entra-device-1'))->asset;
        $asset->update(['organization_id' => $this->departmentA->id]);

        $this->reconcile($this->entraA, $this->entraSnapshot('entra-device-1'));
        $this->reconcile($this->rmm, $this->rmmSnapshot('endpoint-1', 'entra-device-1'));

        $this->assertSame($this->departmentA->id, (int) $asset->fresh()->organization_id);
        $this->assertSame(1, Asset::query()->withoutGlobalScopes()->count());
    }

    public function test_integration_never_claims_a_device_under_another_customer(): void
    {
        $entraB = $this->integration($this->customerB, IntegrationType::MICROSOFT_ENTRA);

        $inA = $this->reconcile($this->entraA, $this->entraSnapshot('entra-device-1'))->asset;
        $inB = $this->reconcile($entraB, $this->entraSnapshot('entra-device-1'))->asset;

        $this->assertNotSame($inA->id, $inB->id);
        $this->assertSame($this->customerA->id, (int) $inA->fresh()->organization_id);
        $this->assertSame($this->customerB->id, (int) $inB->fresh()->organization_id);
    }

    public function test_hostname_only_match_does_not_pull_a_device_down_from_a_higher_level(): void
    {
        $rmmDevice = $this->reconcile($this->rmm, $this->rmmSnapshot('endpoint-1', null, 'PC01'))->asset;

        $activeDirectory = $this->integration($this->customerA, IntegrationType::ACTIVE_DIRECTORY);
        $result = $this->reconcile($activeDirectory, new AssetProviderSnapshot(
            tenantId: $this->tenant->id,
            organizationId: $this->customerA->id,
            organizationIntegrationId: (string) $activeDirectory->id,
            provider: IntegrationType::ACTIVE_DIRECTORY,
            identifiers: [['type' => AssetExternalIdentifierType::Hostname, 'value' => 'PC01']],
            attributes: ['name' => 'PC01', 'primary_hostname' => 'PC01'],
            createAssetIfUnmatched: false,
            matchByUniqueHostname: true,
        ));

        $this->assertNull($result->asset);
        $this->assertSame($this->msp->id, (int) $rmmDevice->fresh()->organization_id);
    }

    public function test_serial_and_hostname_match_moves_device_down_from_a_higher_level(): void
    {
        $rmmDevice = $this->reconcile($this->rmm, $this->rmmSnapshot('endpoint-1', null, 'PC01', 'SN-1'))->asset;

        $result = $this->reconcile($this->entraA, new AssetProviderSnapshot(
            tenantId: $this->tenant->id,
            organizationId: $this->customerA->id,
            organizationIntegrationId: (string) $this->entraA->id,
            provider: IntegrationType::MICROSOFT_ENTRA,
            identifiers: [
                ['type' => AssetExternalIdentifierType::EntraIntuneManagedDeviceId, 'value' => 'intune-1'],
                ['type' => AssetExternalIdentifierType::SerialNumber, 'value' => 'SN-1'],
                ['type' => AssetExternalIdentifierType::Hostname, 'value' => 'PC01'],
            ],
            attributes: ['name' => 'PC01', 'primary_hostname' => 'PC01', 'serial_number' => 'SN-1'],
        ));

        $this->assertSame($rmmDevice->id, $result->asset->id);
        $this->assertSame($this->customerA->id, (int) $rmmDevice->fresh()->organization_id);
    }

    public function test_moving_an_integration_down_moves_its_devices_from_above(): void
    {
        $asset = $this->reconcile($this->rmm, $this->rmmSnapshot('endpoint-1', 'entra-device-1'))->asset;

        $result = app(OrganizationIntegrationMover::class)->move($this->rmm, $this->customerB);

        $this->assertSame(1, $result['moved_devices']);
        $this->assertSame($this->customerB->id, (int) $this->rmm->fresh()->organization_id);
        $this->assertSame($this->customerB->id, (int) $asset->fresh()->organization_id);
    }

    public function test_moving_an_integration_to_another_customer_takes_only_its_own_devices(): void
    {
        $action1A = $this->integration($this->customerA, IntegrationType::ACTION1, 'group-a');

        $own = $this->reconcile($action1A, $this->rmmSnapshot('endpoint-own', null, 'OWN01'))->asset;
        $shared = $this->reconcile($action1A, $this->rmmSnapshot('endpoint-shared', 'entra-device-shared'))->asset;
        $this->reconcile($this->entraA, $this->entraSnapshot('entra-device-shared'));

        $result = app(OrganizationIntegrationMover::class)->move($action1A, $this->customerB);

        $this->assertSame(1, $result['moved_devices']);
        $this->assertSame($this->customerB->id, (int) $own->fresh()->organization_id);
        $this->assertSame($this->customerA->id, (int) $shared->fresh()->organization_id);
    }

    public function test_moving_an_integration_up_leaves_its_devices_where_they_are(): void
    {
        $asset = $this->reconcile($this->entraA, $this->entraSnapshot('entra-device-1'))->asset;

        $result = app(OrganizationIntegrationMover::class)->move($this->entraA, $this->msp);

        $this->assertSame(0, $result['moved_devices']);
        $this->assertSame($this->msp->id, (int) $this->entraA->fresh()->organization_id);
        $this->assertSame($this->customerA->id, (int) $asset->fresh()->organization_id);

        $again = $this->reconcile($this->entraA->fresh(), $this->entraSnapshot('entra-device-1'));
        $this->assertSame($asset->id, $again->asset->id);
    }

    public function test_moving_an_integration_onto_a_duplicate_is_refused(): void
    {
        $this->integration($this->customerB, IntegrationType::MICROSOFT_ENTRA);

        $this->expectException(ValidationException::class);

        app(OrganizationIntegrationMover::class)->move($this->entraA, $this->customerB);
    }

    public function test_moving_an_integration_to_another_tenant_is_refused(): void
    {
        $other = Organization::factory()->create();

        $this->expectException(ValidationException::class);

        app(OrganizationIntegrationMover::class)->move($this->entraA, $other);
    }

    public function test_integrations_page_moves_an_integration_to_another_organization(): void
    {
        $asset = $this->reconcile($this->rmm, $this->rmmSnapshot('endpoint-1', null))->asset;

        $admin = User::factory()->create(['tenant_id' => $this->tenant->id, 'type' => 'agent']);
        setPermissionsTeamId($this->tenant->id);
        $admin->assignRole('Tenant Admin');
        $this->actingAs($admin);
        Filament::setCurrentPanel('agent');
        Filament::setTenant($this->tenant, isQuiet: true);

        Livewire::test(Integrations::class)
            ->callTableAction('move', (string) $this->rmm->id, ['organization_id' => $this->customerB->id])
            ->assertHasNoTableActionErrors()
            ->assertNotified();

        $this->assertSame($this->customerB->id, (int) $this->rmm->fresh()->organization_id);
        $this->assertSame($this->customerB->id, (int) $asset->fresh()->organization_id);
    }

    public function test_coverage_expects_sources_from_integrations_above_the_device(): void
    {
        $this->rmm->update(['enabled' => true, 'config' => ['scopes' => ['endpoints' => true]]]);
        $this->entraA->update(['enabled' => true, 'config' => ['scopes' => ['computers' => true]]]);

        $this->reconcile($this->rmm, $this->rmmSnapshot('endpoint-1', 'entra-device-1', 'PC-BOTH'));
        $this->reconcile($this->entraA, $this->intuneSnapshot('intune-1', 'entra-device-1'));
        $this->reconcile($this->entraA, $this->intuneSnapshot('intune-2', 'entra-device-2'));

        $report = app(DeviceCoverageService::class)->build($this->tenant->id, $this->customerA->id);
        $rows = collect($report->rows)->keyBy('name');

        $this->assertSame([], $rows['PC-entra-device-1']['findings']);
        $this->assertSame(DeviceCoverageReport::CELL_ACTIVE, $rows['PC-entra-device-1']['cells'][DeviceSourceKey::ACTION1]);
        $this->assertSame(['device_management_to_remote_management'], $rows['PC-entra-device-2']['findings']);
        $this->assertSame(DeviceCoverageReport::CELL_MISSING, $rows['PC-entra-device-2']['cells'][DeviceSourceKey::ACTION1]);
    }

    public function test_lineage_lists_ancestors_and_descendants(): void
    {
        $lineage = new OrganizationLineage;

        $this->assertSame([$this->customerA->id, $this->msp->id], $lineage->ancestorIds($this->tenant->id, $this->departmentA->id));
        $this->assertEqualsCanonicalizing(
            [$this->customerA->id, $this->customerB->id, $this->departmentA->id],
            $lineage->descendantIds($this->tenant->id, $this->msp->id),
        );
        $this->assertTrue($lineage->isStrictAncestor($this->tenant->id, null, $this->msp->id));
        $this->assertTrue($lineage->isStrictAncestor($this->tenant->id, $this->msp->id, $this->departmentA->id));
        $this->assertFalse($lineage->isStrictAncestor($this->tenant->id, $this->customerB->id, $this->departmentA->id));
        $this->assertSame(3, $lineage->depth($this->tenant->id, $this->departmentA->id));
        $this->assertNotContains($this->customerB->id, $lineage->scopeIds($this->tenant->id, $this->customerA->id));
    }

    private function integration(Organization $organization, string $type, string $scopeKey = ''): OrganizationIntegration
    {
        return OrganizationIntegration::factory()->create([
            'tenant_id' => $this->tenant->id,
            'organization_id' => $organization->id,
            'type' => $type,
            'scope_key' => $scopeKey,
        ]);
    }

    private function reconcile(OrganizationIntegration $integration, AssetProviderSnapshot $snapshot): AssetReconciliationResult
    {
        $snapshot->organizationId = $integration->organization_id !== null ? (int) $integration->organization_id : null;
        $snapshot->organizationIntegrationId = (string) $integration->id;

        return app(AssetReconciliationService::class)->reconcile($snapshot);
    }

    private function rmmSnapshot(string $endpointId, ?string $entraDeviceId, string $hostname = 'PC-RMM', ?string $serial = null): AssetProviderSnapshot
    {
        $identifiers = [['type' => AssetExternalIdentifierType::Action1EndpointId, 'value' => $endpointId]];
        if ($entraDeviceId !== null) {
            $identifiers[] = ['type' => AssetExternalIdentifierType::EntraAzureAdDeviceId, 'value' => $entraDeviceId];
        }
        if ($serial !== null) {
            $identifiers[] = ['type' => AssetExternalIdentifierType::SerialNumber, 'value' => $serial];
        }
        $identifiers[] = ['type' => AssetExternalIdentifierType::Hostname, 'value' => $hostname];

        return new AssetProviderSnapshot(
            tenantId: $this->tenant->id,
            organizationId: $this->rmm->organization_id,
            organizationIntegrationId: (string) $this->rmm->id,
            provider: IntegrationType::ACTION1,
            identifiers: $identifiers,
            attributes: ['name' => $hostname, 'primary_hostname' => $hostname, 'serial_number' => $serial],
            sourceRecord: new DeviceSourceRecordData(source: DeviceSourceKey::ACTION1, providerRecordId: $endpointId, displayName: $hostname, hostname: $hostname, operatingSystem: 'Windows 11 Enterprise', lastActivityAt: now()->subDay()),
        );
    }

    private function intuneSnapshot(string $intuneId, string $entraDeviceId): AssetProviderSnapshot
    {
        return new AssetProviderSnapshot(
            tenantId: $this->tenant->id,
            organizationId: $this->customerA->id,
            organizationIntegrationId: (string) $this->entraA->id,
            provider: IntegrationType::MICROSOFT_ENTRA,
            identifiers: [
                ['type' => AssetExternalIdentifierType::EntraIntuneManagedDeviceId, 'value' => $intuneId],
                ['type' => AssetExternalIdentifierType::EntraAzureAdDeviceId, 'value' => $entraDeviceId],
            ],
            attributes: ['name' => 'PC-'.$entraDeviceId],
            sourceRecord: new DeviceSourceRecordData(
                source: DeviceSourceKey::MICROSOFT_INTUNE,
                providerRecordId: $intuneId,
                displayName: 'PC-'.$entraDeviceId,
                operatingSystem: 'Windows 11 Enterprise',
                lastActivityAt: now()->subDay(),
            ),
        );
    }

    private function entraSnapshot(string $entraDeviceId): AssetProviderSnapshot
    {
        return new AssetProviderSnapshot(
            tenantId: $this->tenant->id,
            organizationId: $this->customerA->id,
            organizationIntegrationId: (string) $this->entraA->id,
            provider: IntegrationType::MICROSOFT_ENTRA,
            identifiers: [
                ['type' => AssetExternalIdentifierType::EntraAzureAdDeviceId, 'value' => $entraDeviceId],
            ],
            attributes: ['name' => 'PC-'.$entraDeviceId],
            sourceRecord: new DeviceSourceRecordData(source: 'microsoft_entra_device', providerRecordId: $entraDeviceId, displayName: 'PC-'.$entraDeviceId),
        );
    }
}
