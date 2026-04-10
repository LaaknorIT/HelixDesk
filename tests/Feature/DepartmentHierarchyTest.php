<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Organization;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DepartmentHierarchyTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_have_department_in_same_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $organization = Organization::factory()->create(['tenant_id' => $tenant->id]);
        $department = Department::factory()->create([
            'tenant_id' => $tenant->id,
            'organization_id' => $organization->id,
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'organization_id' => $organization->id,
            'department_id' => $department->id,
            'type' => 'end_user',
        ]);

        $user->load('department');
        $this->assertSame((int) $department->id, (int) $user->department_id);
        $this->assertSame((int) $department->id, (int) $user->department->id);
    }

    public function test_department_can_have_nested_children(): void
    {
        $tenant = Tenant::factory()->create();
        $organization = Organization::factory()->create(['tenant_id' => $tenant->id]);
        $parent = Department::factory()->create([
            'tenant_id' => $tenant->id,
            'organization_id' => $organization->id,
        ]);
        $child = Department::factory()->create([
            'tenant_id' => $tenant->id,
            'organization_id' => $organization->id,
            'parent_department_id' => $parent->id,
        ]);

        $this->assertTrue($parent->children()->whereKey($child->id)->exists());
    }
}
