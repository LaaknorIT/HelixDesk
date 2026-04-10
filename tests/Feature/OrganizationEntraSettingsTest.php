<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Tenant;
use App\Support\MicrosoftEntraIntegrationCatalog;
use App\Support\OrganizationIntegrationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationEntraSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_organization_persists_microsoft_entra_integration_payload(): void
    {
        $tenant = Tenant::factory()->create();
        $organization = Organization::factory()->create(['tenant_id' => $tenant->id]);

        $organization->organizational_settings = [
            'integrations' => [
                'microsoft_entra' => [
                    'enabled' => true,
                    'azure_tenant_id' => 'tenant-uuid',
                    'application_client_id' => 'client-uuid',
                    'scopes' => [
                        'users' => true,
                        'departments' => false,
                        'computers' => true,
                        'security_incidents' => false,
                    ],
                ],
            ],
        ];
        $organization->save();

        $organization->refresh();

        $this->assertTrue((bool) data_get($organization->organizational_settings, 'integrations.microsoft_entra.enabled'));
        $this->assertEqualsCanonicalizing(
            ['DeviceManagementManagedDevices.Read.All', 'User.Read.All'],
            MicrosoftEntraIntegrationCatalog::collectApplicationPermissions([
                'users' => true,
                'departments' => false,
                'computers' => true,
                'security_incidents' => false,
            ])
        );
    }

    public function test_legacy_microsoft_entra_surfaces_as_virtual_list_entry(): void
    {
        $raw = [
            'integrations' => [
                'microsoft_entra' => [
                    'enabled' => true,
                    'azure_tenant_id' => 'tenant-a',
                    'application_client_id' => 'client-a',
                    'scopes' => [
                        'users' => true,
                        'departments' => false,
                        'computers' => false,
                        'security_incidents' => false,
                    ],
                ],
            ],
        ];

        $entries = OrganizationIntegrationSettings::listEntries($raw);
        $this->assertCount(1, $entries);
        $this->assertSame(OrganizationIntegrationSettings::LEGACY_MICROSOFT_ENTRA_ROW_ID, $entries[0]['id']);
        $this->assertSame(OrganizationIntegrationSettings::TYPE_MICROSOFT_ENTRA, $entries[0]['type']);
        $this->assertTrue($entries[0]['config']['enabled']);
        $this->assertSame('tenant-a', $entries[0]['config']['azure_tenant_id']);
    }

    public function test_append_microsoft_entra_migrates_legacy_before_adding_new_row(): void
    {
        $legacyOnly = [
            'integrations' => [
                'microsoft_entra' => [
                    'enabled' => true,
                    'azure_tenant_id' => 'legacy-tenant',
                    'application_client_id' => 'legacy-client',
                    'scopes' => [
                        'users' => true,
                        'departments' => false,
                        'computers' => false,
                        'security_incidents' => false,
                    ],
                ],
            ],
        ];

        $merged = OrganizationIntegrationSettings::appendMicrosoftEntraIntegration($legacyOnly, [
            'entra_enabled' => false,
            'entra_azure_tenant_id' => 'new-tenant',
            'entra_application_client_id' => 'new-client',
            'entra_scope_users' => false,
            'entra_scope_departments' => false,
            'entra_scope_computers' => false,
            'entra_scope_security_incidents' => false,
        ]);

        $this->assertNull(data_get($merged, 'integrations.microsoft_entra'));
        $this->assertCount(2, $merged['settings']);
        $this->assertSame('legacy-tenant', $merged['settings'][0]['config']['azure_tenant_id']);
        $this->assertSame('new-tenant', $merged['settings'][1]['config']['azure_tenant_id']);
    }

    public function test_replace_legacy_row_writes_settings_and_strips_integrations_key(): void
    {
        $legacyOnly = [
            'integrations' => [
                'microsoft_entra' => [
                    'enabled' => false,
                    'azure_tenant_id' => 'old',
                    'application_client_id' => '',
                    'scopes' => [
                        'users' => false,
                        'departments' => false,
                        'computers' => false,
                        'security_incidents' => false,
                    ],
                ],
            ],
        ];

        $merged = OrganizationIntegrationSettings::replaceMicrosoftEntraIntegration(
            $legacyOnly,
            OrganizationIntegrationSettings::LEGACY_MICROSOFT_ENTRA_ROW_ID,
            [
                'entra_enabled' => true,
                'entra_azure_tenant_id' => 'updated-tenant',
                'entra_application_client_id' => 'updated-client',
                'entra_scope_users' => true,
                'entra_scope_departments' => false,
                'entra_scope_computers' => false,
                'entra_scope_security_incidents' => false,
            ],
        );

        $this->assertNull(data_get($merged, 'integrations.microsoft_entra'));
        $this->assertCount(1, $merged['settings']);
        $this->assertTrue($merged['settings'][0]['config']['enabled']);
        $this->assertSame('updated-tenant', $merged['settings'][0]['config']['azure_tenant_id']);
    }

    public function test_replace_preserves_client_secret_when_secret_field_not_resubmitted(): void
    {
        $out = [
            'settings' => [[
                'id' => 'int-secret-test',
                'type' => OrganizationIntegrationSettings::TYPE_MICROSOFT_ENTRA,
                'config' => [
                    'enabled' => true,
                    'azure_tenant_id' => 'tenant-x',
                    'application_client_id' => 'client-x',
                    'client_secret' => 'stored-secret-value',
                    'scopes' => [
                        'users' => true,
                        'departments' => false,
                        'computers' => false,
                        'security_incidents' => false,
                    ],
                ],
            ]],
        ];

        $merged = OrganizationIntegrationSettings::replaceMicrosoftEntraIntegration(
            $out,
            'int-secret-test',
            [
                'entra_enabled' => true,
                'entra_azure_tenant_id' => 'tenant-x',
                'entra_application_client_id' => 'client-x',
                'entra_client_secret' => '',
                'entra_scope_users' => true,
                'entra_scope_departments' => false,
                'entra_scope_computers' => false,
                'entra_scope_security_incidents' => false,
            ],
        );

        $this->assertSame('stored-secret-value', $merged['settings'][0]['config']['client_secret']);
    }
}
