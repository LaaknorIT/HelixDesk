<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Filament\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GlobalAdminPanelAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_global_admin_panel_access_requires_is_admin_true(): void
    {
        $panel = Panel::make()->id('global-admin');

        $nonAdminUser = User::factory()->create([
            'is_admin' => false,
        ]);

        $adminUser = User::factory()->create([
            'is_admin' => true,
        ]);

        $this->assertFalse($nonAdminUser->canAccessPanel($panel));
        $this->assertTrue($adminUser->canAccessPanel($panel));
    }

    public function test_other_panels_are_not_blocked_by_is_admin_flag(): void
    {
        $agentPanel = Panel::make()->id('agent');

        $nonAdminUserNoTenant = User::factory()->create([
            'is_admin' => false,
            'tenant_id' => null,
            'type' => 'agent',
        ]);

        $this->assertFalse($nonAdminUserNoTenant->canAccessPanel($agentPanel));

        $tenant = Tenant::factory()->create();

        $agentUser = User::factory()->create([
            'is_admin' => false,
            'tenant_id' => $tenant->id,
            'type' => 'agent',
        ]);

        $this->assertTrue($agentUser->canAccessPanel($agentPanel));

        $endUserPanel = Panel::make()->id('end-user');

        $endUser = User::factory()->create([
            'is_admin' => false,
            'tenant_id' => $tenant->id,
            'type' => 'end_user',
        ]);

        $this->assertTrue($endUser->canAccessPanel($endUserPanel));
    }
}
