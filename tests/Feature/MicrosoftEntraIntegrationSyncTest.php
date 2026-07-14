<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Jobs\OrganizationSettingsImport;
use App\Jobs\SyncNextMicrosoftEntraIntegrationJob;
use App\Models\MonitoringEvent;
use App\Models\Organization;
use App\Models\Tenant;
use App\Services\MicrosoftEntra\MicrosoftEntraOrganizationSyncService;
use App\Support\EntraSyncTarget;
use App\Support\OrganizationIntegrationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MicrosoftEntraIntegrationSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_imports_directory_user_and_department(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();
            if (str_contains($url, 'login.microsoftonline.com')) {
                return Http::response(['access_token' => 'fake-token', 'expires_in' => 3600], 200);
            }
            if (str_contains($url, 'graph.microsoft.com/v1.0/users')) {
                return Http::response([
                    'value' => [[
                        'id' => '11111111-1111-1111-1111-111111111111',
                        'userPrincipalName' => 'syncuser@example.test',
                        'mail' => 'syncuser@example.test',
                        'displayName' => 'Sync User',
                        'department' => 'Operations',
                        'userType' => 'Member',
                        'accountEnabled' => true,
                    ]],
                ], 200);
            }

            return Http::response(['error' => 'unexpected '.$url], 500);
        });

        $tenant = Tenant::factory()->create();
        $organization = Organization::factory()->create([
            'tenant_id' => $tenant->id,
            'organizational_settings' => [
                'settings' => [[
                    'id' => 'int-sync-1',
                    'type' => OrganizationIntegrationSettings::TYPE_MICROSOFT_ENTRA,
                    'config' => [
                        'enabled' => true,
                        'azure_tenant_id' => 'tenant-guid',
                        'application_client_id' => 'client-guid',
                        'client_secret' => 'secret',
                        'scopes' => [
                            'users' => true,
                            'departments' => true,
                            'computers' => false,
                            'security_work_items' => false,
                        ],
                    ],
                ]],
            ],
        ]);

        $target = new EntraSyncTarget(
            tenantId: (int) $tenant->id,
            organizationId: (int) $organization->id,
            integrationId: 'int-sync-1',
            config: $organization->organizational_settings['settings'][0]['config'],
        );

        app(MicrosoftEntraOrganizationSyncService::class)->sync($target);

        $department = Organization::query()
            ->where('tenant_id', $tenant->id)
            ->where('parent_id', $organization->id)
            ->where('type', OrganizationType::Department)
            ->where('name', 'Operations')
            ->first();

        $this->assertNotNull($department);

        $this->assertDatabaseHas('users', [
            'tenant_id' => $tenant->id,
            'organization_id' => $department->id,
            'email' => 'syncuser@example.test',
            'entra_object_id' => '11111111-1111-1111-1111-111111111111',
            'type' => 'end_user',
        ]);

        $organization->refresh();
        $this->assertNotNull(data_get($organization->organizational_settings, 'settings.0.config.last_entra_sync_at'));
        $this->assertNull(data_get($organization->organizational_settings, 'settings.0.config.last_entra_sync_error'));
    }

    public function test_security_incident_sync_creates_and_closes_monitoring_event(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();
            if (str_contains($url, 'login.microsoftonline.com')) {
                return Http::response(['access_token' => 'fake-token', 'expires_in' => 3600], 200);
            }
            if (str_contains($url, 'graph.microsoft.com/v1.0/security/work_items')) {
                return Http::response([
                    'value' => [[
                        'id' => 'inc-42',
                        'displayName' => 'Suspicious activity',
                        'status' => 'resolved',
                        'severity' => 'high',
                    ]],
                ], 200);
            }

            return Http::response(['value' => []], 200);
        });

        $tenant = Tenant::factory()->create();
        $organization = Organization::factory()->create([
            'tenant_id' => $tenant->id,
            'organizational_settings' => [
                'settings' => [[
                    'id' => 'int-sec',
                    'type' => OrganizationIntegrationSettings::TYPE_MICROSOFT_ENTRA,
                    'config' => [
                        'enabled' => true,
                        'azure_tenant_id' => 'tenant-guid',
                        'application_client_id' => 'client-guid',
                        'client_secret' => 'secret',
                        'scopes' => [
                            'users' => false,
                            'departments' => false,
                            'computers' => false,
                            'security_work_items' => true,
                        ],
                    ],
                ]],
            ],
        ]);

        $target = new EntraSyncTarget(
            tenantId: (int) $tenant->id,
            organizationId: (int) $organization->id,
            integrationId: 'int-sec',
            config: $organization->organizational_settings['settings'][0]['config'],
        );

        app(MicrosoftEntraOrganizationSyncService::class)->sync($target);

        $event = MonitoringEvent::query()
            ->where('tenant_id', $tenant->id)
            ->where('external_reference', 'inc-42')
            ->first();

        $this->assertNotNull($event);
        $this->assertSame('closed', $event->status);
        $this->assertNotNull($event->resolved_at);
    }

    public function test_microsoft_entra_import_due_respects_interval(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-10 12:00:00', 'UTC'));

        $tenant = Tenant::factory()->create();
        $baseConfig = [
            'enabled' => true,
            'azure_tenant_id' => 'tenant-guid',
            'application_client_id' => 'client-guid',
            'client_secret' => 'secret',
            'scopes' => ['users' => true, 'departments' => false, 'computers' => false, 'security_work_items' => false],
        ];

        Organization::factory()->create([
            'tenant_id' => $tenant->id,
            'organizational_settings' => [
                'settings' => [[
                    'id' => 'int-recent',
                    'type' => OrganizationIntegrationSettings::TYPE_MICROSOFT_ENTRA,
                    'config' => array_merge($baseConfig, [
                        'last_entra_sync_at' => Carbon::now()->subHours(12)->toIso8601String(),
                    ]),
                ]],
            ],
        ]);

        $this->assertFalse(OrganizationIntegrationSettings::hasMicrosoftEntraIntegrationDueForImport());

        Organization::factory()->create([
            'tenant_id' => $tenant->id,
            'organizational_settings' => [
                'settings' => [[
                    'id' => 'int-stale',
                    'type' => OrganizationIntegrationSettings::TYPE_MICROSOFT_ENTRA,
                    'config' => array_merge($baseConfig, [
                        'last_entra_sync_at' => Carbon::now()->subHours(30)->toIso8601String(),
                    ]),
                ]],
            ],
        ]);

        $this->assertTrue(OrganizationIntegrationSettings::hasMicrosoftEntraIntegrationDueForImport());

        Carbon::setTestNow();
    }

    public function test_organization_settings_import_dispatches_targeted_sync_job(): void
    {
        Bus::fake();

        $tenant = Tenant::factory()->create();
        $organization = Organization::factory()->create([
            'tenant_id' => $tenant->id,
            'organizational_settings' => [
                'settings' => [[
                    'id' => 'int-dispatch',
                    'type' => OrganizationIntegrationSettings::TYPE_MICROSOFT_ENTRA,
                    'config' => [
                        'enabled' => true,
                        'azure_tenant_id' => 'tenant-guid',
                        'application_client_id' => 'client-guid',
                        'client_secret' => 'secret',
                        'scopes' => ['users' => true, 'departments' => false, 'computers' => false, 'security_work_items' => false],
                    ],
                ]],
            ],
        ]);

        (new OrganizationSettingsImport)->handle();

        Bus::assertDispatched(SyncNextMicrosoftEntraIntegrationJob::class, function (SyncNextMicrosoftEntraIntegrationJob $job) use ($organization): bool {
            return $job->organizationId === (int) $organization->id && $job->integrationId === 'int-dispatch';
        });
    }

    public function test_sync_next_microsoft_entra_job_skips_when_no_sync_scopes_enabled(): void
    {
        Http::fake();

        $tenant = Tenant::factory()->create();
        $organization = Organization::factory()->create([
            'tenant_id' => $tenant->id,
            'organizational_settings' => [
                'settings' => [[
                    'id' => 'int-no-scopes',
                    'type' => OrganizationIntegrationSettings::TYPE_MICROSOFT_ENTRA,
                    'config' => [
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
                    ],
                ]],
            ],
        ]);

        (new SyncNextMicrosoftEntraIntegrationJob((int) $organization->id, 'int-no-scopes'))
            ->handle(app(MicrosoftEntraOrganizationSyncService::class));

        $organization->refresh();
        $this->assertNull(data_get($organization->organizational_settings, 'settings.0.config.last_entra_sync_at'));
        Http::assertNothingSent();
    }

    public function test_sync_next_microsoft_entra_job_records_failure_without_advancing_cursor(): void
    {
        Http::fake([
            'https://login.microsoftonline.com/*' => Http::response(['error' => 'invalid_client'], 401),
        ]);

        $tenant = Tenant::factory()->create();
        $organization = Organization::factory()->create([
            'tenant_id' => $tenant->id,
            'organizational_settings' => [
                'settings' => [[
                    'id' => 'int-fail',
                    'type' => OrganizationIntegrationSettings::TYPE_MICROSOFT_ENTRA,
                    'config' => [
                        'enabled' => true,
                        'azure_tenant_id' => 'tenant-guid',
                        'application_client_id' => 'client-guid',
                        'client_secret' => 'secret',
                        'scopes' => ['users' => true, 'departments' => false, 'computers' => false, 'security_work_items' => false],
                    ],
                ]],
            ],
        ]);

        (new SyncNextMicrosoftEntraIntegrationJob((int) $organization->id, 'int-fail'))
            ->handle(app(MicrosoftEntraOrganizationSyncService::class));

        $organization->refresh();
        $this->assertNotNull(data_get($organization->organizational_settings, 'settings.0.config.last_entra_sync_error'));
    }
}
