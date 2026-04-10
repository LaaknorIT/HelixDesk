<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\Organization;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Department>
 */
class DepartmentFactory extends Factory
{
    protected $model = Department::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'organization_id' => Organization::factory(),
            'parent_department_id' => null,
            'name' => fake()->unique()->words(2, true).' dept',
            'code' => fake()->optional()->bothify('DEPT-###'),
            'external_reference' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Department $department): void {
            $tenantId = $department->tenant_id;
            if ($tenantId === null) {
                return;
            }

            $orgId = $department->organization_id;
            if ($orgId === null) {
                $department->organization_id = Organization::factory()->create([
                    'tenant_id' => $tenantId,
                ])->id;

                return;
            }

            $org = Organization::query()->find($orgId);
            if ($org === null || (int) $org->tenant_id !== (int) $tenantId) {
                $department->organization_id = Organization::factory()->create([
                    'tenant_id' => $tenantId,
                ])->id;
            }
        });
    }
}
