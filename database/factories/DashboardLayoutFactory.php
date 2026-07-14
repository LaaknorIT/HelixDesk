<?php

namespace Database\Factories;

use App\Models\DashboardLayout;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DashboardLayout>
 */
class DashboardLayoutFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'scope' => DashboardLayout::SCOPE_ROLE_DEFAULT,
            'role_name' => 'Agent',
            'user_id' => null,
            'layout_json' => [
                ['widget' => 'my_work_items', 'x' => 0, 'y' => 0, 'w' => 6, 'h' => 3],
                ['widget' => 'monitoring_events', 'x' => 6, 'y' => 0, 'w' => 6, 'h' => 3],
            ],
            'locked_widgets' => ['my_work_items'],
            'is_active' => true,
        ];
    }
}
