<?php

namespace Database\Factories;

use App\Enums\SoftwareLicenseBillingPeriod;
use App\Enums\SoftwareLicenseMetric;
use App\Enums\SoftwareLicenseType;
use App\Models\SoftwareDeployment;
use App\Models\SoftwareLicense;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SoftwareLicense>
 */
class SoftwareLicenseFactory extends Factory
{
    protected $model = SoftwareLicense::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'software_deployment_id' => SoftwareDeployment::factory(),
            'name' => fake()->words(2, true).' license',
            'sku' => fake()->optional()->bothify('SKU-####'),
            'product_code' => fake()->optional()->bothify('PC-####'),
            'license_type' => SoftwareLicenseType::Subscription,
            'metric' => SoftwareLicenseMetric::Users,
            'seat_count' => fake()->numberBetween(1, 500),
            'seats_used' => fake()->optional()->numberBetween(0, 500),
            'starts_on' => now()->toDateString(),
            'expires_on' => now()->addYear()->toDateString(),
            'auto_renew' => fake()->boolean(),
            'cost_amount' => fake()->optional()->randomFloat(2, 10, 10000),
            'cost_currency' => 'EUR',
            'billing_period' => SoftwareLicenseBillingPeriod::Annual,
            'vendor_contract_reference' => fake()->optional()->bothify('CTR-####'),
            'purchase_order_reference' => fake()->optional()->bothify('PO-####'),
            'notes' => fake()->optional()->sentence(),
            'attributes_json' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (SoftwareLicense $license): void {
            $tenantId = $license->tenant_id;
            if ($tenantId === null) {
                return;
            }

            if ($license->software_deployment_id === null) {
                $license->software_deployment_id = SoftwareDeployment::factory()->create([
                    'tenant_id' => $tenantId,
                ])->id;

                return;
            }

            $deployment = SoftwareDeployment::query()->find($license->software_deployment_id);
            if ($deployment === null || (int) $deployment->tenant_id !== (int) $tenantId) {
                $license->software_deployment_id = SoftwareDeployment::factory()->create([
                    'tenant_id' => $tenantId,
                ])->id;
            }
        });
    }
}
