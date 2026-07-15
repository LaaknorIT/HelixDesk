<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\OrganizationIntegration;
use App\Models\Tenant;
use App\Support\IntegrationType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrganizationIntegration>
 */
class OrganizationIntegrationFactory extends Factory
{
    protected $model = OrganizationIntegration::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'organization_id' => Organization::factory(),
            'type' => IntegrationType::MICROSOFT_ENTRA,
            'enabled' => true,
            'config' => [
                'enabled' => true,
                'azure_tenant_id' => fake()->uuid(),
                'application_client_id' => fake()->uuid(),
                'scopes' => [
                    'users' => true,
                    'departments' => false,
                    'computers' => false,
                    'security_work_items' => false,
                ],
            ],
            'credentials' => [
                'client_secret' => 'secret',
            ],
            'api_base_url' => null,
            'last_sync_at' => null,
            'last_sync_error' => null,
            'sync_interval_hours' => null,
            'scope_key' => '',
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (OrganizationIntegration $integration): void {
            $tenantId = $integration->tenant_id;
            if ($tenantId === null) {
                return;
            }

            $organizationId = $integration->organization_id;
            if ($organizationId !== null) {
                $organization = Organization::query()->withoutGlobalScopes()->find($organizationId);
                if ($organization !== null && (int) $organization->tenant_id !== (int) $tenantId) {
                    $integration->organization_id = Organization::factory()->create([
                        'tenant_id' => $tenantId,
                    ])->id;
                }
            }

            $config = is_array($integration->config) ? $integration->config : [];
            $integration->scope_key = OrganizationIntegration::resolveScopeKey(
                (string) $integration->type,
                $config,
            );
        });
    }

    public function microsoftEntra(): static
    {
        return $this->state(fn (): array => [
            'type' => IntegrationType::MICROSOFT_ENTRA,
        ]);
    }

    public function action1(): static
    {
        return $this->state(fn (): array => [
            'type' => IntegrationType::ACTION1,
            'config' => [
                'enabled' => true,
                'action1_organization_id' => fake()->uuid(),
                'action1_group_id' => fake()->uuid(),
            ],
            'credentials' => [
                'client_secret' => 'secret',
            ],
            'api_base_url' => config('helixdesk_integrations.action1.default_api_base_url'),
            'scope_key' => '',
        ]);
    }
}
