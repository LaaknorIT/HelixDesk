<?php

namespace Database\Factories;

use App\Models\Change;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Change>
 */
class ChangeFactory extends Factory
{
    protected $model = Change::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'title' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'risk_id' => null,
            'status' => 'Draft',
            'requested_by_user_id' => User::factory(),
            'approved_by_user_id' => null,
            'implementation_plan' => null,
            'rollback_plan' => null,
            'scheduled_start_at' => null,
            'scheduled_end_at' => null,
        ];
    }
}
