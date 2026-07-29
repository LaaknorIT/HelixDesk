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
            'name' => fake()->words(3, true).' service',
            'description' => fake()->sentence(),
            'code' => fake()->optional()->bothify('SRV-####'),
            'kind' => 'custom',
            'attributes_json' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (ServiceOffering $offering): void {
            if ($offering->organizations()->exists()) {
                return;
            }

            $organization = Organization::factory()->create([
                'tenant_id' => $offering->tenant_id,
            ]);

            $offering->organizations()->attach($organization->id);
        });
    }
}
