<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_tenant_via_api_and_observer_creates_default_roles(): void
    {
        $this->actingAs(User::factory()->create([
            'is_admin' => true,
        ]));

        $response = $this->postJson('/api/v1/tenants', [
            'name' => 'Acme Managed Services',
            'slug' => 'acme-managed-services',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Acme Managed Services')
            ->assertJsonPath('data.slug', 'acme-managed-services')
            ->assertJsonPath('data.status', 'active');

        $tenantId = $response->json('data.id');

        $this->assertDatabaseHas('roles', [
            'team_id' => $tenantId,
            'name' => 'Tenant Admin',
        ]);

        $this->assertDatabaseHas('roles', [
            'team_id' => $tenantId,
            'name' => 'Agent',
        ]);
    }

    public function test_it_validates_tenant_creation_payload(): void
    {
        $this->actingAs(User::factory()->create([
            'is_admin' => true,
        ]));

        $response = $this->postJson('/api/v1/tenants', [
            'name' => '',
            'status' => 'unknown',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'status']);
    }
}
