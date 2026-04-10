<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantRole;
use App\Support\HelixdeskPermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TenantObserverTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_default_tenant_admin_and_agent_roles_when_a_tenant_is_created(): void
    {
        $tenant = Tenant::factory()->create();

        $this->assertDatabaseHas('roles', [
            'team_id' => $tenant->id,
            'name' => 'Tenant Admin',
            'guard_name' => 'web',
        ]);

        $this->assertDatabaseHas('roles', [
            'team_id' => $tenant->id,
            'name' => 'Agent',
            'guard_name' => 'web',
        ]);

        $this->assertDatabaseHas('role_permissions', [
            'permission' => 'tenant.admin',
            'guard_name' => 'web',
        ]);
    }

    public function test_it_is_idempotent_for_role_creation_on_tenant_creation(): void
    {
        $tenant = Tenant::factory()->create();

        Tenant::findOrFail($tenant->id)->save();

        $this->assertSame(
            1,
            TenantRole::query()
                ->where('team_id', $tenant->id)
                ->where('name', 'Tenant Admin')
                ->count()
        );

        $this->assertSame(
            1,
            TenantRole::query()
                ->where('team_id', $tenant->id)
                ->where('name', 'Agent')
                ->count()
        );
    }

    public function test_it_assigns_all_default_permissions_to_tenant_admin_role(): void
    {
        $tenant = Tenant::factory()->create();

        $adminRole = TenantRole::query()
            ->where('team_id', $tenant->id)
            ->where('name', 'Tenant Admin')
            ->firstOrFail();

        $incidentActionCount = count(HelixdeskPermissionCatalog::incidentModuleActions());
        $expectedRolePermissionCount = count(HelixdeskPermissionCatalog::allPermissionNames()) - ($incidentActionCount - 1);

        $this->assertSame(
            $expectedRolePermissionCount,
            DB::table('role_permissions')->where('role_id', $adminRole->getKey())->count()
        );
    }

    public function test_it_registers_every_permission_key_from_config(): void
    {
        $tenant = Tenant::factory()->create();

        $adminRole = TenantRole::query()
            ->where('team_id', $tenant->id)
            ->where('name', 'Tenant Admin')
            ->firstOrFail();

        $assigned = DB::table('role_permissions')
            ->where('role_id', $adminRole->getKey())
            ->pluck('permission')
            ->all();

        foreach (HelixdeskPermissionCatalog::modulesWithActionsExcludingIncident() as $module => $cfg) {
            foreach ($cfg['actions'] as $action) {
                $this->assertContains("{$module}.{$action}", $assigned);
            }
        }

        $this->assertContains('incident.admin', $assigned);

        $general = $tenant->fresh()->generalQueue();
        $this->assertNotNull($general);

        $pivot = DB::table('role_queue')
            ->where('role_id', $adminRole->getKey())
            ->where('queue_id', $general->id)
            ->first();

        $this->assertNotNull($pivot);

        $incidentPivot = json_decode((string) $pivot->incident_permissions, true);
        $this->assertIsArray($incidentPivot);

        foreach (HelixdeskPermissionCatalog::incidentModuleActions() as $action) {
            $this->assertTrue($incidentPivot[$action] ?? false);
        }

        $agentRole = TenantRole::query()
            ->where('team_id', $tenant->id)
            ->where('name', 'Agent')
            ->firstOrFail();

        $agentAssigned = DB::table('role_permissions')
            ->where('role_id', $agentRole->getKey())
            ->pluck('permission')
            ->all();

        foreach ($agentAssigned as $permission) {
            $this->assertFalse(
                str_starts_with((string) $permission, 'incident.') && (string) $permission !== 'incident.admin'
            );
        }
    }
}
