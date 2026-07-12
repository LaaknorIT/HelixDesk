<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Filament\Agent\Resources\Organizations\OrganizationResource;
use App\Models\Organization;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationHierarchyTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_belong_to_department_organization_node(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Organization::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => OrganizationType::Customer,
        ]);
        $department = Organization::factory()->department()->create([
            'tenant_id' => $tenant->id,
            'parent_id' => $customer->id,
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'organization_id' => $department->id,
            'type' => 'end_user',
        ]);

        $user->load('organization');
        $this->assertSame((int) $department->id, (int) $user->organization_id);
        $this->assertSame(OrganizationType::Department, $user->organization->type);
    }

    public function test_organization_can_have_nested_children(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Organization::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => OrganizationType::Customer,
        ]);
        $parent = Organization::factory()->department()->create([
            'tenant_id' => $tenant->id,
            'parent_id' => $customer->id,
        ]);
        $child = Organization::factory()->department()->create([
            'tenant_id' => $tenant->id,
            'parent_id' => $parent->id,
        ]);

        $this->assertTrue($parent->children()->whereKey($child->id)->exists());
    }

    public function test_agent_with_organization_create_permission_can_create_customer(): void
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

        $this->assertTrue($agent->hasPermissionTo('organization.create'));
        $this->assertTrue(OrganizationResource::canCreate());
    }

    public function test_service_offering_requires_customer_organization(): void
    {
        $tenant = Tenant::factory()->create();
        $group = Organization::factory()->group()->create(['tenant_id' => $tenant->id]);

        $agent = User::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => 'agent',
        ]);

        setPermissionsTeamId($tenant->id);
        $agent->assignRole('Agent');
        setPermissionsTeamId(null);

        $this->actingAs($agent)
            ->postJson('/api/v1/service-offerings', [
                'organization_id' => $group->id,
                'name' => 'Invalid offering',
                'kind' => 'custom',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['organization_id']);
    }
}
