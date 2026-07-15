<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationIntegration;
use App\Models\Tenant;
use App\Services\OrganizationIntegrationMigrator;
use App\Support\IntegrationType;
use App\Support\OrganizationIntegrationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MigrateOrganizationIntegrationsMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_data_migration_moves_org_json_integrations_into_table(): void
    {
        $tenant = Tenant::factory()->create();
        $entraId = (string) Str::uuid();

        Organization::factory()->create([
            'tenant_id' => $tenant->id,
            'organizational_settings' => [
                'settings' => [[
                    'id' => $entraId,
                    'type' => OrganizationIntegrationSettings::TYPE_MICROSOFT_ENTRA,
                    'config' => [
                        'enabled' => true,
                        'azure_tenant_id' => 'azure-tenant',
                        'application_client_id' => 'client-id',
                        'client_secret' => 'entra-secret',
                        'scopes' => [
                            'users' => true,
                            'departments' => false,
                            'computers' => true,
                            'security_work_items' => false,
                        ],
                    ],
                ]],
            ],
        ]);

        app(OrganizationIntegrationMigrator::class)->migrateAll();

        $integration = OrganizationIntegration::query()->withoutGlobalScopes()->find($entraId);
        $this->assertNotNull($integration);
        $this->assertSame(IntegrationType::MICROSOFT_ENTRA, $integration->type);
        $this->assertSame('entra-secret', $integration->credentials['client_secret'] ?? null);
    }

    public function test_data_migration_is_idempotent(): void
    {
        $tenant = Tenant::factory()->create();
        $entraId = (string) Str::uuid();

        Organization::factory()->create([
            'tenant_id' => $tenant->id,
            'organizational_settings' => [
                'settings' => [[
                    'id' => $entraId,
                    'type' => OrganizationIntegrationSettings::TYPE_MICROSOFT_ENTRA,
                    'config' => [
                        'enabled' => true,
                        'azure_tenant_id' => 'azure-tenant',
                        'application_client_id' => 'client-id',
                        'client_secret' => 'entra-secret',
                        'scopes' => ['users' => true, 'departments' => false, 'computers' => false, 'security_work_items' => false],
                    ],
                ]],
            ],
        ]);

        $migrator = app(OrganizationIntegrationMigrator::class);
        $first = $migrator->migrateAll();
        $second = $migrator->migrateAll();

        $this->assertSame(1, $first);
        $this->assertSame(0, $second);
        $this->assertSame(1, OrganizationIntegration::query()->withoutGlobalScopes()->count());
    }

    public function test_data_migration_strips_org_json_after_migrate(): void
    {
        $tenant = Tenant::factory()->create();
        $entraId = (string) Str::uuid();

        Organization::factory()->create([
            'tenant_id' => $tenant->id,
            'organizational_settings' => [
                'settings' => [[
                    'id' => $entraId,
                    'type' => OrganizationIntegrationSettings::TYPE_MICROSOFT_ENTRA,
                    'config' => [
                        'enabled' => true,
                        'azure_tenant_id' => 'azure-tenant',
                        'application_client_id' => 'client-id',
                        'client_secret' => 'entra-secret',
                        'scopes' => ['users' => true, 'departments' => false, 'computers' => false, 'security_work_items' => false],
                    ],
                ]],
            ],
        ]);

        $migrator = app(OrganizationIntegrationMigrator::class);
        $migrator->migrateAll();
        $migrator->stripAllLegacyIntegrationJson();

        $organization = Organization::query()->first();
        $this->assertSame([], $organization->organizational_settings['settings'] ?? []);
        $this->assertNotNull(OrganizationIntegration::query()->withoutGlobalScopes()->find($entraId));
    }
}
