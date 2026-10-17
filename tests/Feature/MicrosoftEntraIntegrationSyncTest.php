<?php

namespace Tests\Feature;

use App\Enums\AssetExternalIdentifierType;
use App\Enums\OrganizationType;
use App\Jobs\OrganizationSettingsImport;
use App\Jobs\SyncNextMicrosoftEntraIntegrationJob;
use App\Models\Asset;
use App\Models\MonitoringEvent;
use App\Models\Organization;
use App\Models\OrganizationIntegration;
use App\Models\Tenant;
use App\Services\MicrosoftEntra\MicrosoftEntraOrganizationSyncService;
use App\Support\EntraSyncTarget;
use App\Support\OrganizationIntegrationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
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
        $organization = Organization::factory()->create(['tenant_id' => $tenant->id]);
        $integration = $this->createEntraIntegration($tenant, $organization, 'int-sync-1', [
            'scopes' => [
                'users' => true,
                'departments' => true,
                'computers' => false,
                'security_work_items' => false,
            ],
        ]);

        $target = $this->entraTargetFromIntegration($tenant, $organization, $integration);

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
            'password' => null,
            'identity_integration_id' => 'int-sync-1',
        ]);

        $integration->refresh();
        $this->assertNotNull($integration->last_sync_at);
        $this->assertNull($integration->last_sync_error);
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
        $organization = Organization::factory()->create(['tenant_id' => $tenant->id]);
        $integration = $this->createEntraIntegration($tenant, $organization, 'int-sec', [
            'scopes' => [
                'users' => false,
                'departments' => false,
                'computers' => false,
                'security_work_items' => true,
            ],
        ]);

        app(MicrosoftEntraOrganizationSyncService::class)->sync(
            $this->entraTargetFromIntegration($tenant, $organization, $integration),
        );

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
        $organization = Organization::factory()->create(['tenant_id' => $tenant->id]);

        $this->createEntraIntegration($tenant, $organization, 'int-recent', [
            'last_sync_at' => Carbon::now()->subHours(12),
        ]);

        $this->assertFalse(OrganizationIntegrationSettings::hasMicrosoftEntraIntegrationDueForImport());

        $staleOrg = Organization::factory()->create(['tenant_id' => $tenant->id]);
        $this->createEntraIntegration($tenant, $staleOrg, 'int-stale', [
            'last_sync_at' => Carbon::now()->subHours(30),
        ]);

        $this->assertTrue(OrganizationIntegrationSettings::hasMicrosoftEntraIntegrationDueForImport());

        Carbon::setTestNow();
    }

    public function test_organization_settings_import_dispatches_targeted_sync_job(): void
    {
        Bus::fake();

        $tenant = Tenant::factory()->create();
        $organization = Organization::factory()->create(['tenant_id' => $tenant->id]);
        $this->createEntraIntegration($tenant, $organization, 'int-dispatch');

        (new OrganizationSettingsImport)->handle();

        Bus::assertDispatched(SyncNextMicrosoftEntraIntegrationJob::class, function (SyncNextMicrosoftEntraIntegrationJob $job) use ($organization): bool {
            return $job->organizationId === (int) $organization->id && $job->integrationId === 'int-dispatch';
        });
    }

    public function test_sync_next_microsoft_entra_job_skips_when_no_sync_scopes_enabled(): void
    {
        Http::fake();

        $tenant = Tenant::factory()->create();
        $organization = Organization::factory()->create(['tenant_id' => $tenant->id]);
        $this->createEntraIntegration($tenant, $organization, 'int-no-scopes', [
            'scopes' => [
                'users' => false,
                'departments' => false,
                'computers' => false,
                'security_work_items' => false,
            ],
        ]);

        (new SyncNextMicrosoftEntraIntegrationJob((int) $organization->id, 'int-no-scopes'))
            ->handle(app(MicrosoftEntraOrganizationSyncService::class));

        $integration = OrganizationIntegration::query()->withoutGlobalScopes()->find('int-no-scopes');
        $this->assertNotNull($integration);
        $this->assertNull($integration->last_sync_at);
        Http::assertNothingSent();
    }

    public function test_sync_next_microsoft_entra_job_records_failure_without_advancing_cursor(): void
    {
        Http::fake([
            'https://login.microsoftonline.com/*' => Http::response(['error' => 'invalid_client'], 401),
        ]);

        $tenant = Tenant::factory()->create();
        $organization = Organization::factory()->create(['tenant_id' => $tenant->id]);
        $this->createEntraIntegration($tenant, $organization, 'int-fail');

        (new SyncNextMicrosoftEntraIntegrationJob((int) $organization->id, 'int-fail'))
            ->handle(app(MicrosoftEntraOrganizationSyncService::class));

        $integration = OrganizationIntegration::query()->withoutGlobalScopes()->find('int-fail');
        $this->assertNotNull($integration);
        $this->assertNotNull($integration->last_sync_error);
    }

    public function test_managed_device_sync_creates_external_identifiers_not_asset_identifier_guids(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();
            if (str_contains($url, 'login.microsoftonline.com')) {
                return Http::response(['access_token' => 'fake-token', 'expires_in' => 3600], 200);
            }
            if (str_contains($url, 'graph.microsoft.com/v1.0/deviceManagement/managedDevices')) {
                return Http::response([
                    'value' => [[
                        'id' => '22222222-2222-2222-2222-222222222222',
                        'deviceName' => 'DESKTOP-ENTRA',
                        'serialNumber' => 'SN-ENTRA-001',
                        'azureADDeviceId' => '11111111-1111-1111-1111-111111111111',
                        'operatingSystem' => 'Windows 11',
                        'userPrincipalName' => 'device.user@example.test',
                        'emailAddress' => 'device.user@example.test',
                    ]],
                ], 200);
            }

            return Http::response(['value' => []], 200);
        });

        $tenant = Tenant::factory()->create();
        $organization = Organization::factory()->create(['tenant_id' => $tenant->id]);
        $integration = $this->createEntraIntegration($tenant, $organization, (string) Str::uuid(), [
            'scopes' => [
                'users' => false,
                'departments' => false,
                'computers' => true,
                'security_work_items' => false,
            ],
        ]);

        app(MicrosoftEntraOrganizationSyncService::class)->sync(
            $this->entraTargetFromIntegration($tenant, $organization, $integration),
        );

        $asset = Asset::query()
            ->where('tenant_id', $tenant->id)
            ->where('organization_id', $organization->id)
            ->where('name', 'DESKTOP-ENTRA')
            ->first();

        $this->assertNotNull($asset);
        $this->assertFalse(
            (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', (string) $asset->asset_identifier)
        );

        $this->assertDatabaseHas('asset_external_identifiers', [
            'asset_id' => $asset->id,
            'provider' => OrganizationIntegrationSettings::TYPE_MICROSOFT_ENTRA,
            'identifier_type' => AssetExternalIdentifierType::EntraAzureAdDeviceId->value,
            'external_id' => '11111111-1111-1111-1111-111111111111',
        ]);
        $this->assertDatabaseHas('asset_external_identifiers', [
            'asset_id' => $asset->id,
            'provider' => OrganizationIntegrationSettings::TYPE_MICROSOFT_ENTRA,
            'identifier_type' => AssetExternalIdentifierType::EntraIntuneManagedDeviceId->value,
            'external_id' => '22222222-2222-2222-2222-222222222222',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createEntraIntegration(Tenant $tenant, Organization $organization, string $id, array $overrides = []): OrganizationIntegration
    {
        $scopes = $overrides['scopes'] ?? [
            'users' => true,
            'departments' => false,
            'computers' => false,
            'security_work_items' => false,
        ];

        return OrganizationIntegration::query()->create([
            'id' => $id,
            'tenant_id' => $tenant->id,
            'organization_id' => $organization->id,
            'type' => OrganizationIntegrationSettings::TYPE_MICROSOFT_ENTRA,
            'enabled' => true,
            'config' => [
                'azure_tenant_id' => 'tenant-guid',
                'application_client_id' => 'client-guid',
                'scopes' => $scopes,
            ],
            'credentials' => ['client_secret' => 'secret'],
            'last_sync_at' => $overrides['last_sync_at'] ?? null,
            'last_sync_error' => null,
            'scope_key' => '',
        ]);
    }

    private function entraTargetFromIntegration(Tenant $tenant, Organization $organization, OrganizationIntegration $integration): EntraSyncTarget
    {
        $entry = OrganizationIntegrationSettings::findEntryForOrganization($organization, (string) $integration->getKey());
        $config = is_array($entry['config'] ?? null) ? $entry['config'] : [];

        return new EntraSyncTarget(
            tenantId: (int) $tenant->id,
            organizationId: (int) $organization->id,
            integrationId: (string) $integration->getKey(),
            config: $config,
        );
    }
}
