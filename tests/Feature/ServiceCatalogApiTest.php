<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Document;
use App\Models\Organization;
use App\Models\ServiceOffering;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class ServiceCatalogApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_can_create_supplier_and_list(): void
    {
        $tenant = Tenant::factory()->create();

        $agent = User::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => 'agent',
        ]);

        setPermissionsTeamId($tenant->id);
        $agent->assignRole('Agent');
        setPermissionsTeamId(null);

        $this->actingAs($agent);

        $this->postJson('/api/v1/suppliers', [
            'name' => 'Acme Telco',
            'notes' => 'Primary carrier',
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Acme Telco');

        $this->getJson('/api/v1/suppliers')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_service_offering_requires_attributes_for_internet_connectivity_kind(): void
    {
        $tenant = Tenant::factory()->create();
        $organization = Organization::factory()->create(['tenant_id' => $tenant->id]);

        $agent = User::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => 'agent',
        ]);

        setPermissionsTeamId($tenant->id);
        $agent->assignRole('Agent');
        setPermissionsTeamId(null);

        $this->actingAs($agent);

        $this->postJson('/api/v1/service-offerings', [
            'organization_id' => $organization->id,
            'name' => 'Main internet',
            'kind' => 'internet_connectivity',
            'attributes_json' => [],
        ])->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);

        $this->postJson('/api/v1/service-offerings', [
            'organization_id' => $organization->id,
            'name' => 'Main internet',
            'kind' => 'internet_connectivity',
            'attributes_json' => ['line_number' => 'L-100'],
        ])->assertCreated()
            ->assertJsonPath('data.kind', 'internet_connectivity')
            ->assertJsonPath('data.attributes_json.line_number', 'L-100');
    }

    public function test_service_offering_show_includes_links(): void
    {
        $tenant = Tenant::factory()->create();
        $organization = Organization::factory()->create(['tenant_id' => $tenant->id]);

        $agent = User::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => 'agent',
        ]);

        setPermissionsTeamId($tenant->id);
        $agent->assignRole('Agent');
        setPermissionsTeamId(null);

        $supplier = Supplier::factory()->create(['tenant_id' => $tenant->id]);
        $asset = Asset::factory()->create(['tenant_id' => $tenant->id, 'organization_id' => $organization->id]);

        $offering = ServiceOffering::factory()->create([
            'tenant_id' => $tenant->id,
            'organization_id' => $organization->id,
            'kind' => 'custom',
        ]);

        $offering->suppliers()->attach($supplier->id, ['role' => 'primary', 'notes' => null]);
        $offering->realizationAssets()->attach($asset->id, ['role' => 'primary_access']);

        $this->actingAs($agent);

        $this->getJson('/api/v1/service-offerings/'.$offering->id)
            ->assertOk()
            ->assertJsonPath('data.suppliers.0.id', $supplier->id)
            ->assertJsonPath('data.realization_assets.0.id', $asset->id);
    }

    public function test_agent_cannot_view_service_offering_from_other_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $organizationA = Organization::factory()->create(['tenant_id' => $tenantA->id]);

        $offering = ServiceOffering::factory()->create([
            'tenant_id' => $tenantA->id,
            'organization_id' => $organizationA->id,
        ]);

        $agentB = User::factory()->create([
            'tenant_id' => $tenantB->id,
            'type' => 'agent',
        ]);

        setPermissionsTeamId($tenantB->id);
        $agentB->assignRole('Agent');
        setPermissionsTeamId(null);

        $this->actingAs($agentB);

        $this->getJson('/api/v1/service-offerings/'.$offering->id)
            ->assertNotFound();
    }

    public function test_document_show_includes_linked_service_offering_and_asset(): void
    {
        $tenant = Tenant::factory()->create();
        $organization = Organization::factory()->create(['tenant_id' => $tenant->id]);

        $agent = User::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => 'agent',
        ]);

        setPermissionsTeamId($tenant->id);
        $agent->assignRole('Agent');
        setPermissionsTeamId(null);

        $document = Document::factory()->create(['tenant_id' => $tenant->id]);
        $offering = ServiceOffering::factory()->create([
            'tenant_id' => $tenant->id,
            'organization_id' => $organization->id,
        ]);
        $asset = Asset::factory()->create(['tenant_id' => $tenant->id, 'organization_id' => $organization->id]);

        $document->serviceOfferings()->attach($offering->id);
        $document->assets()->attach($asset->id);

        $this->actingAs($agent);

        $this->getJson('/api/v1/documents/'.$document->id)
            ->assertOk()
            ->assertJsonPath('data.service_offerings.0.id', $offering->id)
            ->assertJsonPath('data.assets.0.id', $asset->id);
    }
}
