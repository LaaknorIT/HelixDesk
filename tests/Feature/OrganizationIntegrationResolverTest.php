<?php

namespace Tests\Feature;

use App\Models\MailAccount;
use App\Models\Organization;
use App\Models\OrganizationIntegration;
use App\Models\Tenant;
use App\Support\OrganizationIntegrationResolver;
use App\Support\OrganizationIntegrationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class OrganizationIntegrationResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolves_mail_account_microsoft_entra_integration(): void
    {
        $tenant = Tenant::factory()->create();
        $organization = Organization::factory()->create(['tenant_id' => $tenant->id]);
        $integration = OrganizationIntegration::factory()->create([
            'id' => 'entra-1',
            'tenant_id' => $tenant->id,
            'organization_id' => $organization->id,
            'type' => OrganizationIntegrationSettings::TYPE_MICROSOFT_ENTRA,
            'config' => [
                'enabled' => true,
                'azure_tenant_id' => 'tenant-guid',
                'application_client_id' => 'client-guid',
            ],
            'credentials' => ['client_secret' => 'secret'],
        ]);

        $account = MailAccount::factory()->create([
            'tenant_id' => $tenant->id,
            'organization_id' => $organization->id,
            'integration_type' => OrganizationIntegrationSettings::TYPE_MICROSOFT_ENTRA,
            'integration_id' => (string) $integration->getKey(),
        ]);

        $entry = OrganizationIntegrationResolver::resolveMailAccountIntegration($account);

        $this->assertSame('entra-1', $entry['id']);
        $this->assertSame(OrganizationIntegrationSettings::TYPE_MICROSOFT_ENTRA, $entry['type']);
        $this->assertSame('tenant-guid', $entry['config']['azure_tenant_id']);
    }

    public function test_rejects_integration_type_mismatch(): void
    {
        $tenant = Tenant::factory()->create();
        $organization = Organization::factory()->create(['tenant_id' => $tenant->id]);
        OrganizationIntegration::factory()->create([
            'id' => 'entra-1',
            'tenant_id' => $tenant->id,
            'organization_id' => $organization->id,
            'type' => OrganizationIntegrationSettings::TYPE_MICROSOFT_ENTRA,
            'config' => ['enabled' => true],
        ]);

        $account = MailAccount::factory()->create([
            'tenant_id' => $tenant->id,
            'organization_id' => $organization->id,
            'integration_type' => OrganizationIntegrationSettings::TYPE_GOOGLE_WORKSPACE,
            'integration_id' => 'entra-1',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Organization integration type does not match the referenced integration.');

        OrganizationIntegrationResolver::resolveMailAccountIntegration($account);
    }
}
