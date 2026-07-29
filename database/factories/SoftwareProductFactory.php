<?php

namespace Database\Factories;

use App\Models\SoftwareProduct;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SoftwareProduct>
 */
class SoftwareProductFactory extends Factory
{
    protected $model = SoftwareProduct::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->unique()->words(2, true).' '.fake()->randomNumber(4),
            'vendor_name' => fake()->optional()->company(),
            'supplier_id' => null,
            'category' => fake()->optional()->randomElement(['productivity', 'erp', 'security']),
            'description' => fake()->optional()->sentence(),
            'partner_notes' => fake()->optional()->sentence(),
            'external_reference' => fake()->optional()->bothify('REF-####'),
            'is_active' => true,
        ];
    }
}
