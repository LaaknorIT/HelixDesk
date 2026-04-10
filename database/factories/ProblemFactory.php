<?php

namespace Database\Factories;

use App\Models\Problem;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Problem>
 */
class ProblemFactory extends Factory
{
    protected $model = Problem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'title' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'root_cause' => null,
            'workaround' => null,
            'permanent_fix' => null,
            'status' => 'open',
        ];
    }
}
