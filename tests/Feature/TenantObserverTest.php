<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TenantObserverTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_default_admin_and_agent_roles_when_a_tenant_is_created(): void
    {
        $tenant = Tenant::factory()->create();

        $this->assertDatabaseHas('roles', [
            'team_id' => $tenant->id,
            'name' => 'Admin',
            'guard_name' => 'web',
        ]);

        $this->assertDatabaseHas('roles', [
            'team_id' => $tenant->id,
            'name' => 'Agent',
            'guard_name' => 'web',
        ]);

        $this->assertDatabaseHas('permissions', [
            'name' => 'manage_permissions',
            'guard_name' => 'web',
        ]);
    }

    public function test_it_is_idempotent_for_role_creation_on_tenant_creation(): void
    {
        $tenant = Tenant::factory()->create();

        Tenant::findOrFail($tenant->id)->save();

        $this->assertSame(
            1,
            Role::query()
                ->where('team_id', $tenant->id)
                ->where('name', 'Admin')
                ->count()
        );

        $this->assertSame(
            1,
            Role::query()
                ->where('team_id', $tenant->id)
                ->where('name', 'Agent')
                ->count()
        );
    }

    public function test_it_assigns_all_default_permissions_to_tenant_admin_role(): void
    {
        $tenant = Tenant::factory()->create();

        $adminRole = Role::query()
            ->where('team_id', $tenant->id)
            ->where('name', 'Admin')
            ->firstOrFail();

        $this->assertSame(
            Permission::query()->count(),
            $adminRole->permissions()->count()
        );
    }
}
