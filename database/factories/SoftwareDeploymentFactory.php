<?php

namespace Database\Factories;

use App\Enums\SoftwareDeploymentEnvironment;
use App\Enums\SoftwareDeploymentStatus;
use App\Models\Organization;
use App\Models\SoftwareDeployment;
use App\Models\SoftwareProduct;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SoftwareDeployment>
 */
class SoftwareDeploymentFactory extends Factory
{
    protected $model = SoftwareDeployment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'software_product_id' => SoftwareProduct::factory(),
            'organization_id' => Organization::factory(),
            'name' => fake()->words(3, true).' deployment',
            'environment' => SoftwareDeploymentEnvironment::Production,
            'status' => SoftwareDeploymentStatus::Active,
            'external_tenant_id' => fake()->optional()->uuid(),
            'admin_url' => fake()->optional()->url(),
            'notes' => fake()->optional()->sentence(),
            'attributes_json' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (SoftwareDeployment $deployment): void {
            $tenantId = $deployment->tenant_id;
            if ($tenantId === null) {
                return;
            }

            if ($deployment->software_product_id === null) {
                $deployment->software_product_id = SoftwareProduct::factory()->create([
                    'tenant_id' => $tenantId,
                ])->id;
            } else {
                $product = SoftwareProduct::query()->find($deployment->software_product_id);
                if ($product === null || (int) $product->tenant_id !== (int) $tenantId) {
                    $deployment->software_product_id = SoftwareProduct::factory()->create([
                        'tenant_id' => $tenantId,
                    ])->id;
                }
            }

            if ($deployment->organization_id === null) {
                $deployment->organization_id = Organization::factory()->create([
                    'tenant_id' => $tenantId,
                ])->id;
            } else {
                $organization = Organization::query()->find($deployment->organization_id);
                if ($organization === null || (int) $organization->tenant_id !== (int) $tenantId) {
                    $deployment->organization_id = Organization::factory()->create([
                        'tenant_id' => $tenantId,
                    ])->id;
                }
            }
        });
    }
}
