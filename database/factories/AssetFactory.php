<?php

namespace Database\Factories;

use App\Models\Asset;
use App\Models\AssetType;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Asset>
 */
class AssetFactory extends Factory
{
    protected $model = Asset::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'organization_id' => null,
            'asset_type_id' => AssetType::factory(),
            'name' => fake()->words(3, true),
            'asset_identifier' => fake()->bothify('AST-####'),
            'serial_number' => null,
            'purchase_date' => null,
            'warranty_date' => null,
            'lifecycle_status' => 'in_use',
            'owner_user_id' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Asset $asset): void {
            $tenantId = $asset->tenant_id;
            if ($tenantId === null) {
                return;
            }

            $typeId = $asset->asset_type_id;
            if ($typeId === null) {
                $asset->asset_type_id = AssetType::factory()->create([
                    'tenant_id' => $tenantId,
                ])->id;

                return;
            }

            $type = AssetType::query()->find($typeId);
            if ($type === null || (int) $type->tenant_id !== (int) $tenantId) {
                $asset->asset_type_id = AssetType::factory()->create([
                    'tenant_id' => $tenantId,
                ])->id;
            }
        });
    }
}
