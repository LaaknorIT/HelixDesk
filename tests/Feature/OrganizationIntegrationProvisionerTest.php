<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Tenant;
use App\Support\OrganizationIntegrationProvisioner;
use App\Support\OrganizationIntegrationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationIntegrationProvisionerTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_google_workspace_integration_on_organization(): void
    {
        $tenant = Tenant::factory()->create();
        $organization = Organization::factory()->create(['tenant_id' => $tenant->id]);

        $result = OrganizationIntegrationProvisioner::create(
            $organization,
            OrganizationIntegrationSettings::TYPE_GOOGLE_WORKSPACE,
            [
                'gws_customer_domain' => 'acme.example',
                'gws_service_account_client_email' => 'mail@acme.iam.gserviceaccount.com',
                'gws_service_account_private_key' => '-----BEGIN PRIVATE KEY-----',
                'gws_domain_wide_delegation_client_id' => '1234567890',
                'gws_enabled' => true,
            ],
        );

        $organization->refresh();
        $entry = OrganizationIntegrationSettings::findEntry(
            is_array($organization->organizational_settings) ? $organization->organizational_settings : [],
            $result['integration_id'],
        );

        $this->assertSame($result['integration_id'], $entry['id'] ?? null);
        $this->assertSame(OrganizationIntegrationSettings::TYPE_GOOGLE_WORKSPACE, $entry['type'] ?? null);
        $this->assertSame('acme.example', $entry['config']['customer_domain'] ?? null);
        $this->assertTrue((bool) ($entry['config']['enabled'] ?? false));
    }

    public function test_creates_microsoft_entra_integration_on_organization(): void
    {
        $tenant = Tenant::factory()->create();
        $organization = Organization::factory()->create(['tenant_id' => $tenant->id]);

        $result = OrganizationIntegrationProvisioner::create(
            $organization,
            OrganizationIntegrationSettings::TYPE_MICROSOFT_ENTRA,
            [
                'entra_azure_tenant_id' => 'tenant-guid',
                'entra_application_client_id' => 'client-guid',
                'entra_client_secret' => 'secret',
                'entra_enabled' => true,
            ],
        );

        $organization->refresh();
        $entry = OrganizationIntegrationSettings::findEntry(
            is_array($organization->organizational_settings) ? $organization->organizational_settings : [],
            $result['integration_id'],
        );

        $this->assertSame(OrganizationIntegrationSettings::TYPE_MICROSOFT_ENTRA, $entry['type'] ?? null);
        $this->assertSame('tenant-guid', $entry['config']['azure_tenant_id'] ?? null);
    }
}
