<?php

namespace Tests\Feature;

use App\Models\DashboardLayout;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardLayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_creation_seeds_role_default_dashboard_layouts(): void
    {
        $tenant = Tenant::factory()->create();

        $this->assertDatabaseHas('dashboard_layouts', [
            'tenant_id' => $tenant->id,
            'scope' => DashboardLayout::SCOPE_ROLE_DEFAULT,
            'role_name' => 'Tenant Admin',
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('dashboard_layouts', [
            'tenant_id' => $tenant->id,
            'scope' => DashboardLayout::SCOPE_ROLE_DEFAULT,
            'role_name' => 'Agent',
            'is_active' => true,
        ]);
    }

    public function test_it_resolves_role_default_layout_when_no_user_override_exists(): void
    {
        $tenant = Tenant::factory()->create();
        $agent = User::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => 'agent',
        ]);

        setPermissionsTeamId($tenant->id);
        $agent->assignRole('Agent');
        setPermissionsTeamId(null);

        $layout = DashboardLayout::resolveEffectiveForUser($agent);

        $this->assertNotNull($layout);
        $this->assertSame(DashboardLayout::SCOPE_ROLE_DEFAULT, $layout->scope);
        $this->assertSame('Agent', $layout->role_name);
    }

    public function test_user_override_layout_takes_precedence_over_role_default(): void
    {
        $tenant = Tenant::factory()->create();
        $agent = User::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => 'agent',
        ]);

        setPermissionsTeamId($tenant->id);
        $agent->assignRole('Agent');
        setPermissionsTeamId(null);

        DashboardLayout::query()->create([
            'tenant_id' => $tenant->id,
            'scope' => DashboardLayout::SCOPE_USER_OVERRIDE,
            'role_name' => null,
            'user_id' => $agent->id,
            'layout_json' => [
                ['widget' => 'custom_widget', 'x' => 0, 'y' => 0, 'w' => 12, 'h' => 2],
            ],
            'locked_widgets' => [],
            'is_active' => true,
        ]);

        $layout = DashboardLayout::resolveEffectiveForUser($agent);

        $this->assertNotNull($layout);
        $this->assertSame(DashboardLayout::SCOPE_USER_OVERRIDE, $layout->scope);
        $this->assertSame($agent->id, $layout->user_id);
    }
}
