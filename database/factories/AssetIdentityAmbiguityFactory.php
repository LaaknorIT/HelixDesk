<?php

namespace Database\Factories;

use App\Enums\AssetIdentityAmbiguityStatus;
use App\Models\AssetIdentityAmbiguity;
use App\Models\Organization;
use App\Models\OrganizationIntegration;
use App\Models\Tenant;
use App\Support\IntegrationType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AssetIdentityAmbiguity>
 */
class AssetIdentityAmbiguityFactory extends Factory
{
    protected $model = AssetIdentityAmbiguity::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $tenant = Tenant::factory()->create();
        $organization = Organization::factory()->create(['tenant_id' => $tenant->id]);
        $integration = OrganizationIntegration::factory()->create([
            'tenant_id' => $tenant->id,
            'organization_id' => $organization->id,
            'type' => IntegrationType::ACTION1,
        ]);

        return [
            'tenant_id' => $tenant->id,
            'organization_id' => $organization->id,
            'organization_integration_id' => $integration->id,
            'provider' => IntegrationType::ACTION1,
            'candidate_asset_ids' => [],
            'snapshot_json' => [
                'identifiers' => [],
                'attributes' => ['name' => 'Ambiguous device'],
            ],
            'status' => AssetIdentityAmbiguityStatus::Open,
            'resolved_asset_id' => null,
        ];
    }
}
