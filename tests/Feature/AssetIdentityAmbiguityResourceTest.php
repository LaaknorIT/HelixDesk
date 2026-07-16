<?php

namespace Tests\Feature;

use App\Enums\AssetIdentityAmbiguityStatus;
use App\Filament\Agent\Resources\AssetIdentityAmbiguities\Pages\ListAssetIdentityAmbiguities;
use App\Filament\Agent\Resources\AssetIdentityAmbiguities\Pages\ViewAssetIdentityAmbiguity;
use App\Models\AssetIdentityAmbiguity;
use App\Models\Organization;
use App\Models\OrganizationIntegration;
use App\Models\Tenant;
use App\Models\User;
use App\Support\IntegrationType;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AssetIdentityAmbiguityResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_ambiguity_belongs_to_tenant_for_filament_ownership(): void
    {
        $tenant = Tenant::factory()->create();
        $organization = Organization::factory()->create(['tenant_id' => $tenant->id]);
        $integration = OrganizationIntegration::factory()->create([
            'tenant_id' => $tenant->id,
            'organization_id' => $organization->id,
            'type' => IntegrationType::ACTION1,
        ]);

        $ambiguity = AssetIdentityAmbiguity::factory()->create([
            'tenant_id' => $tenant->id,
            'organization_id' => $organization->id,
            'organization_integration_id' => $integration->id,
            'provider' => IntegrationType::ACTION1,
            'status' => AssetIdentityAmbiguityStatus::Open,
        ]);

        $this->assertTrue($ambiguity->isRelation('tenant'));
        $this->assertTrue($ambiguity->tenant()->exists());
        $this->assertTrue($ambiguity->tenant->is($tenant));
    }

    public function test_agent_can_list_and_view_identity_ambiguities_in_tenant_panel(): void
    {
        $tenant = Tenant::factory()->create();
        $organization = Organization::factory()->create(['tenant_id' => $tenant->id]);
        $integration = OrganizationIntegration::factory()->create([
            'tenant_id' => $tenant->id,
            'organization_id' => $organization->id,
            'type' => IntegrationType::ACTION1,
        ]);
        $ambiguity = AssetIdentityAmbiguity::factory()->create([
            'tenant_id' => $tenant->id,
            'organization_id' => $organization->id,
            'organization_integration_id' => $integration->id,
            'provider' => IntegrationType::ACTION1,
            'status' => AssetIdentityAmbiguityStatus::Open,
            'snapshot_json' => [
                'identifiers' => [],
                'attributes' => ['name' => 'Conflicted laptop'],
            ],
        ]);

        $agent = User::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => 'agent',
        ]);

        setPermissionsTeamId($tenant->id);
        $agent->assignRole('Agent');
        setPermissionsTeamId(null);

        $this->actingAs($agent);

        Filament::setCurrentPanel('agent');
        Filament::setTenant($tenant, isQuiet: true);
        setPermissionsTeamId($tenant->id);

        try {
            Livewire::test(ListAssetIdentityAmbiguities::class, [
                'tenant' => $tenant,
            ])
                ->assertOk()
                ->assertCanSeeTableRecords([$ambiguity]);

            Livewire::test(ViewAssetIdentityAmbiguity::class, [
                'tenant' => $tenant,
                'record' => $ambiguity->getKey(),
            ])
                ->assertOk();
        } finally {
            setPermissionsTeamId(null);
        }
    }
}
