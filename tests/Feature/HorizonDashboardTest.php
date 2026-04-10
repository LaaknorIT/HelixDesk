<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HorizonDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_horizon_dashboard_forbids_guests(): void
    {
        $this->get('/horizon')->assertForbidden();
    }

    public function test_horizon_dashboard_forbids_non_admin_users(): void
    {
        $user = User::factory()->create([
            'is_admin' => false,
        ]);

        $this->actingAs($user);

        $this->get('/horizon')->assertForbidden();
    }

    public function test_horizon_dashboard_allows_admin_users(): void
    {
        $user = User::factory()->create([
            'is_admin' => true,
            'tenant_id' => null,
            'type' => 'global_admin',
        ]);

        $this->actingAs($user);

        $this->get('/horizon')->assertOk();
    }
}
