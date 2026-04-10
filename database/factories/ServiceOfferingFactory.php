<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\ServiceOffering;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServiceOffering>
 */
class ServiceOfferingFactory extends Factory
{
    protected $model = ServiceOffering::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'organization_id' => Organization::factory(),
            'name' => fake()->words(3, true).' service',
            'description' => fake()->sentence(),
            'code' => fake()->optional()->bothify('SRV-####'),
            'kind' => 'custom',
            'attributes_json' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (ServiceOffering $offering): void {
            $tenantId = $offering->tenant_id;
            if ($tenantId === null) {
                return;
            }

            $orgId = $offering->organization_id;
            if ($orgId === null) {
                $offering->organization_id = Organization::factory()->create([
                    'tenant_id' => $tenantId,
                ])->id;

                return;
            }

            $org = Organization::query()->find($orgId);
            if ($org === null || (int) $org->tenant_id !== (int) $tenantId) {
                $offering->organization_id = Organization::factory()->create([
                    'tenant_id' => $tenantId,
                ])->id;
            }
        });
    }
}
