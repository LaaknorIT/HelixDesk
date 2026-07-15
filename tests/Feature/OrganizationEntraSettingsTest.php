<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Tenant;
use App\Repositories\OrganizationIntegrationRepository;
use App\Support\MicrosoftEntraIntegrationCatalog;
use App\Support\OrganizationIntegrationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OrganizationEntraSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_repository_persists_microsoft_entra_integration(): void
    {
        $tenant = Tenant::factory()->create();
        $organization = Organization::factory()->create(['tenant_id' => $tenant->id]);

        $integration = app(OrganizationIntegrationRepository::class)->createMicrosoftEntraFromForm($organization, [
            'entra_enabled' => true,
            'entra_azure_tenant_id' => 'tenant-uuid',
            'entra_application_client_id' => 'client-uuid',
            'entra_client_secret' => 'secret',
            'entra_scope_users' => true,
            'entra_scope_departments' => false,
            'entra_scope_computers' => true,
            'entra_scope_security_work_items' => false,
        ]);

        $this->assertTrue($integration->enabled);
        $this->assertSame('tenant-uuid', $integration->config['azure_tenant_id'] ?? null);
        $this->assertSame('secret', $integration->credentials['client_secret'] ?? null);

        $this->assertEqualsCanonicalizing(
            ['DeviceManagementManagedDevices.Read.All', 'User.Read.All'],
            MicrosoftEntraIntegrationCatalog::collectApplicationPermissions([
                'users' => true,
                'departments' => false,
                'computers' => true,
                'security_work_items' => false,
            ])
        );
    }

    public function test_entra_config_sync_skip_reason_when_no_scopes_enabled(): void
    {
        $config = [
            'enabled' => true,
            'azure_tenant_id' => 'tenant-guid',
            'application_client_id' => 'client-guid',
            'client_secret' => 'secret',
            'scopes' => [
                'users' => false,
                'departments' => false,
                'computers' => false,
                'security_work_items' => false,
            ],
        ];

        $this->assertSame('no_sync_scopes_enabled', OrganizationIntegrationSettings::entraConfigSyncSkipReason($config));
        $this->assertFalse(OrganizationIntegrationSettings::entraConfigReadyForSync($config));
    }

    public function test_validate_entra_form_rejects_enabled_integration_without_scopes(): void
    {
        $this->expectException(ValidationException::class);

        OrganizationIntegrationSettings::validateEntraFormDataForSave([
            'entra_enabled' => true,
            'entra_azure_tenant_id' => 'tenant-guid',
            'entra_application_client_id' => 'client-guid',
            'entra_client_secret' => 'secret',
            'entra_scope_users' => false,
            'entra_scope_departments' => false,
            'entra_scope_computers' => false,
            'entra_scope_security_work_items' => false,
        ]);
    }

    public function test_update_preserves_client_secret_when_secret_field_not_resubmitted(): void
    {
        $tenant = Tenant::factory()->create();
        $organization = Organization::factory()->create(['tenant_id' => $tenant->id]);

        $integration = app(OrganizationIntegrationRepository::class)->createMicrosoftEntraFromForm($organization, [
            'entra_enabled' => true,
            'entra_azure_tenant_id' => 'tenant-x',
            'entra_application_client_id' => 'client-x',
            'entra_client_secret' => 'stored-secret-value',
            'entra_scope_users' => true,
            'entra_scope_departments' => false,
            'entra_scope_computers' => false,
            'entra_scope_security_work_items' => false,
        ]);

        app(OrganizationIntegrationRepository::class)->updateMicrosoftEntraFromForm(
            $organization,
            (string) $integration->getKey(),
            [
                'entra_enabled' => true,
                'entra_azure_tenant_id' => 'tenant-x',
                'entra_application_client_id' => 'client-x',
                'entra_client_secret' => '',
                'entra_scope_users' => true,
                'entra_scope_departments' => false,
                'entra_scope_computers' => false,
                'entra_scope_security_work_items' => false,
            ],
        );

        $integration->refresh();
        $this->assertSame('stored-secret-value', $integration->credentials['client_secret'] ?? null);
    }
}
