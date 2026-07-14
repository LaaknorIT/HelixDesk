<?php

namespace Tests\Feature;

use App\Filament\GlobalAdmin\Resources\Tenants\Pages\EditTenant;
use App\Filament\GlobalAdmin\Resources\Tenants\RelationManagers\TenantAdminsRelationManager;
use App\Models\Tenant;
use App\Models\TenantRole;
use App\Models\User;
use App\Services\TenantAdminAssignmentService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class GlobalAdminTenantAdminAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_assign_to_tenant_agent_stacks_tenant_admin_role_with_agent_role(): void
    {
        $tenant = Tenant::factory()->create();

        $agent = User::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => 'agent',
            'is_admin' => false,
        ]);

        setPermissionsTeamId($tenant->id);
        $agent->assignRole('Agent');
        setPermissionsTeamId(null);

        app(TenantAdminAssignmentService::class)->assign($tenant, $agent);

        $tenantAdminRole = $this->tenantAdminRoleFor($tenant);

        $this->assertDatabaseHas('model_has_roles', [
            'model_id' => $agent->id,
            'model_type' => $agent->getMorphClass(),
            'team_id' => $tenant->id,
            'role_id' => $tenantAdminRole->id,
        ]);

        setPermissionsTeamId($tenant->id);

        try {
            $this->assertTrue($agent->fresh()->hasRole('Agent'));
            $this->assertTrue($agent->fresh()->hasRole('Tenant Admin'));
        } finally {
            setPermissionsTeamId(null);
        }
    }

    public function test_assign_to_global_admin_does_not_change_tenant_id(): void
    {
        $tenant = Tenant::factory()->create();

        $globalAdmin = User::factory()->create([
            'tenant_id' => null,
            'type' => 'agent',
            'is_admin' => true,
        ]);

        app(TenantAdminAssignmentService::class)->assign($tenant, $globalAdmin);

        $globalAdmin->refresh();

        $this->assertNull($globalAdmin->tenant_id);
        $this->assertTrue($globalAdmin->is_admin);

        $tenantAdminRole = $this->tenantAdminRoleFor($tenant);

        $this->assertDatabaseHas('model_has_roles', [
            'model_id' => $globalAdmin->id,
            'model_type' => $globalAdmin->getMorphClass(),
            'team_id' => $tenant->id,
            'role_id' => $tenantAdminRole->id,
        ]);
    }

    public function test_eligible_candidates_exclude_existing_admins_end_users_and_other_tenant_agents(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();

        $eligibleAgent = User::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => 'agent',
        ]);

        $existingAdmin = User::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => 'agent',
        ]);

        setPermissionsTeamId($tenant->id);
        $existingAdmin->assignRole('Tenant Admin');
        setPermissionsTeamId(null);

        $endUser = User::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => 'end_user',
        ]);

        $otherTenantAgent = User::factory()->create([
            'tenant_id' => $otherTenant->id,
            'type' => 'agent',
        ]);

        $globalAdmin = User::factory()->create([
            'tenant_id' => null,
            'is_admin' => true,
            'type' => 'agent',
        ]);

        $candidates = app(TenantAdminAssignmentService::class)
            ->eligibleCandidates($tenant)
            ->pluck('id')
            ->all();

        $this->assertContains($eligibleAgent->id, $candidates);
        $this->assertContains($globalAdmin->id, $candidates);
        $this->assertNotContains($existingAdmin->id, $candidates);
        $this->assertNotContains($endUser->id, $candidates);
        $this->assertNotContains($otherTenantAgent->id, $candidates);
    }

    public function test_assign_rejects_user_already_tenant_admin(): void
    {
        $tenant = Tenant::factory()->create();

        $admin = User::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => 'agent',
        ]);

        setPermissionsTeamId($tenant->id);
        $admin->assignRole('Tenant Admin');
        setPermissionsTeamId(null);

        $this->expectException(ValidationException::class);

        app(TenantAdminAssignmentService::class)->assign($tenant, $admin);
    }

    public function test_non_global_admin_cannot_access_global_admin_tenant_edit(): void
    {
        $tenant = Tenant::factory()->create();

        $agent = User::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => 'agent',
            'is_admin' => false,
        ]);

        $this->actingAs($agent)
            ->get(route('filament.global-admin.resources.tenants.edit', ['record' => $tenant]))
            ->assertForbidden();
    }

    public function test_global_admin_can_assign_tenant_administrator_from_relation_manager(): void
    {
        $tenant = Tenant::factory()->create();

        $globalAdmin = User::factory()->create([
            'is_admin' => true,
            'tenant_id' => null,
            'type' => 'agent',
        ]);

        $agent = User::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => 'agent',
        ]);

        setPermissionsTeamId($tenant->id);
        $agent->assignRole('Agent');
        setPermissionsTeamId(null);

        $this->actingAs($globalAdmin);

        Filament::setCurrentPanel('global-admin');

        Livewire::test(TenantAdminsRelationManager::class, [
            'ownerRecord' => $tenant,
            'pageClass' => EditTenant::class,
        ])
            ->callAction(TestAction::make('addTenantAdministrator')->table(), data: [
                'user_id' => $agent->id,
            ])
            ->assertNotified();

        $tenantAdminRole = $this->tenantAdminRoleFor($tenant);

        $this->assertDatabaseHas('model_has_roles', [
            'model_id' => $agent->id,
            'model_type' => $agent->getMorphClass(),
            'team_id' => $tenant->id,
            'role_id' => $tenantAdminRole->id,
        ]);
    }

    private function tenantAdminRoleFor(Tenant $tenant): TenantRole
    {
        return TenantRole::query()
            ->where('team_id', $tenant->id)
            ->where('name', config('helixdesk_permissions.tenant_roles.tenant_admin.name'))
            ->firstOrFail();
    }
}
