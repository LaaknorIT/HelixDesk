<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\SoftwareDeploymentStatus;
use App\Enums\SoftwareLicenseType;
use App\Models\Asset;
use App\Models\Document;
use App\Models\Organization;
use App\Models\ServiceOffering;
use App\Models\SoftwareDeployment;
use App\Models\SoftwareLicense;
use App\Models\SoftwareProduct;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceOfferingsAndSoftwareTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_offering_can_be_assigned_to_multiple_organizations_including_groups(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Organization::factory()->create(['tenant_id' => $tenant->id]);
        $group = Organization::factory()->group()->create(['tenant_id' => $tenant->id]);

        $agent = User::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => 'agent',
        ]);

        setPermissionsTeamId($tenant->id);
        $agent->assignRole('Agent');
        setPermissionsTeamId(null);

        $this->actingAs($agent);

        $response = $this->postJson('/api/v1/service-offerings', [
            'organization_ids' => [$customer->id, $group->id],
            'name' => 'Office support',
            'kind' => 'custom',
        ])->assertCreated();

        $offeringId = $response->json('data.id');
        $offering = ServiceOffering::query()->findOrFail($offeringId);

        $this->assertEqualsCanonicalizing(
            [$customer->id, $group->id],
            $offering->organizations()->pluck('organizations.id')->all(),
        );
    }

    public function test_same_customer_can_have_multiple_offerings_of_same_kind(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Organization::factory()->create(['tenant_id' => $tenant->id]);

        $first = ServiceOffering::factory()->create([
            'tenant_id' => $tenant->id,
            'kind' => 'custom',
            'name' => 'Office support A',
        ]);
        $first->organizations()->sync([$customer->id]);

        $second = ServiceOffering::factory()->create([
            'tenant_id' => $tenant->id,
            'kind' => 'custom',
            'name' => 'Office support B',
        ]);
        $second->organizations()->sync([$customer->id]);

        $this->assertCount(2, $customer->serviceOfferings()->where('kind', 'custom')->get());
    }

    public function test_software_deployment_can_belong_to_multiple_service_offerings(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Organization::factory()->create(['tenant_id' => $tenant->id]);
        $product = SoftwareProduct::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Microsoft 365']);

        $deployment = SoftwareDeployment::factory()->create([
            'tenant_id' => $tenant->id,
            'software_product_id' => $product->id,
            'organization_id' => $customer->id,
            'name' => 'Acme M365',
            'status' => SoftwareDeploymentStatus::Active,
        ]);

        $office = ServiceOffering::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Office support',
        ]);
        $office->organizations()->sync([$customer->id]);

        $identity = ServiceOffering::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Identity support',
        ]);
        $identity->organizations()->sync([$customer->id]);

        $deployment->serviceOfferings()->sync([$office->id, $identity->id]);

        $this->assertCount(2, $deployment->fresh()->serviceOfferings);
        $this->assertTrue($office->softwareDeployments()->whereKey($deployment->id)->exists());
    }

    public function test_software_license_stores_rich_entitlement_fields(): void
    {
        $tenant = Tenant::factory()->create();
        $deployment = SoftwareDeployment::factory()->create(['tenant_id' => $tenant->id]);

        $license = SoftwareLicense::factory()->create([
            'tenant_id' => $tenant->id,
            'software_deployment_id' => $deployment->id,
            'name' => 'M365 E3',
            'license_type' => SoftwareLicenseType::Subscription,
            'seat_count' => 50,
            'seats_used' => 42,
            'expires_on' => now()->addMonths(6)->toDateString(),
            'auto_renew' => true,
            'cost_amount' => 1200.50,
            'cost_currency' => 'EUR',
        ]);

        $this->assertDatabaseHas('software_licenses', [
            'id' => $license->id,
            'seat_count' => 50,
            'seats_used' => 42,
            'auto_renew' => true,
            'cost_currency' => 'EUR',
        ]);
    }

    public function test_documents_can_link_to_product_and_deployment(): void
    {
        $tenant = Tenant::factory()->create();
        $product = SoftwareProduct::factory()->create(['tenant_id' => $tenant->id]);
        $deployment = SoftwareDeployment::factory()->create([
            'tenant_id' => $tenant->id,
            'software_product_id' => $product->id,
        ]);

        $partnerDoc = Document::factory()->create(['tenant_id' => $tenant->id, 'title' => 'Partner CSP notes']);
        $customerDoc = Document::factory()->create(['tenant_id' => $tenant->id, 'title' => 'Acme admin guide']);

        $partnerDoc->softwareProducts()->attach($product->id);
        $customerDoc->softwareDeployments()->attach($deployment->id);

        $this->assertTrue($product->documents()->whereKey($partnerDoc->id)->exists());
        $this->assertTrue($deployment->documents()->whereKey($customerDoc->id)->exists());
        $this->assertFalse($deployment->documents()->whereKey($partnerDoc->id)->exists());
    }

    public function test_deployment_can_link_assets_and_department_cannot_receive_offering(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Organization::factory()->create(['tenant_id' => $tenant->id]);
        $department = Organization::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => OrganizationType::Department,
            'parent_id' => $customer->id,
        ]);

        $this->assertFalse($department->acceptsServiceOfferings());
        $this->assertTrue($customer->acceptsServiceOfferings());

        $deployment = SoftwareDeployment::factory()->create([
            'tenant_id' => $tenant->id,
            'organization_id' => $customer->id,
        ]);
        $asset = Asset::factory()->create([
            'tenant_id' => $tenant->id,
            'organization_id' => $customer->id,
        ]);

        $deployment->assets()->attach($asset->id, ['role' => 'hosted_on']);

        $this->assertSame('hosted_on', $deployment->assets()->first()->pivot->role);
    }

    public function test_api_rejects_department_organization_on_service_offering(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Organization::factory()->create(['tenant_id' => $tenant->id]);
        $department = Organization::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => OrganizationType::Department,
            'parent_id' => $customer->id,
        ]);

        $agent = User::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => 'agent',
        ]);

        setPermissionsTeamId($tenant->id);
        $agent->assignRole('Agent');
        setPermissionsTeamId(null);

        $this->actingAs($agent)
            ->postJson('/api/v1/service-offerings', [
                'organization_ids' => [$department->id],
                'name' => 'Invalid',
                'kind' => 'custom',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['organization_ids']);
    }
}
