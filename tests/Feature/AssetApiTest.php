<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AssetType;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssetApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_with_permission_can_list_and_create_assets(): void
    {
        $tenant = Tenant::factory()->create();

        $agent = User::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => 'agent',
        ]);

        setPermissionsTeamId($tenant->id);
        $agent->assignRole('Agent');
        setPermissionsTeamId(null);

        $assetType = AssetType::query()
            ->where('tenant_id', $tenant->id)
            ->where('name', 'PC')
            ->firstOrFail();

        $this->actingAs($agent);

        $this->getJson('/api/v1/assets')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->postJson('/api/v1/assets', [
            'name' => 'Finance laptop',
            'asset_type_id' => $assetType->id,
            'lifecycle_status' => 'in_use',
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Finance laptop');

        $this->getJson('/api/v1/assets')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_agent_can_show_asset(): void
    {
        $tenant = Tenant::factory()->create();

        $agent = User::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => 'agent',
        ]);

        setPermissionsTeamId($tenant->id);
        $agent->assignRole('Agent');
        setPermissionsTeamId(null);

        $assetType = AssetType::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($agent);

        $create = $this->postJson('/api/v1/assets', [
            'name' => 'Server A',
            'asset_type_id' => $assetType->id,
        ])->assertCreated();

        $id = $create->json('data.id');

        $this->getJson("/api/v1/assets/{$id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Server A');
    }

    public function test_agent_cannot_resolve_asset_from_other_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $agentA = User::factory()->create([
            'tenant_id' => $tenantA->id,
            'type' => 'agent',
        ]);

        setPermissionsTeamId($tenantA->id);
        $agentA->assignRole('Agent');
        setPermissionsTeamId(null);

        $agentB = User::factory()->create([
            'tenant_id' => $tenantB->id,
            'type' => 'agent',
        ]);

        setPermissionsTeamId($tenantB->id);
        $agentB->assignRole('Agent');
        setPermissionsTeamId(null);

        $typeB = AssetType::factory()->create(['tenant_id' => $tenantB->id]);

        $otherAsset = Asset::factory()->create([
            'tenant_id' => $tenantB->id,
            'asset_type_id' => $typeB->id,
            'name' => 'Other tenant asset',
        ]);

        $this->actingAs($agentA);

        $this->getJson("/api/v1/assets/{$otherAsset->id}")
            ->assertNotFound();
    }
}
