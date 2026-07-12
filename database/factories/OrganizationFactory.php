<?php

namespace Database\Factories;

use App\Enums\OrganizationType;
use App\Models\Organization;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'type' => OrganizationType::Customer,
            'parent_id' => Organization::defaultParentKey(),
            'name' => fake()->company(),
            'code' => null,
            'is_billable' => true,
            'order' => 0,
            'external_reference' => null,
        ];
    }

    public function group(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => OrganizationType::Group,
            'is_billable' => false,
        ]);
    }

    public function department(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => OrganizationType::Department,
            'is_billable' => false,
        ]);
    }
}
