<?php

namespace Tests\Feature;

use App\Enums\AssetExternalIdentifierType;
use App\Enums\AssetPatchComplianceStatus;
use App\Jobs\OrganizationSettingsImport;
use App\Jobs\SyncNextAction1IntegrationJob;
use App\Models\Asset;
use App\Models\AssetPatchItem;
use App\Models\Organization;
use App\Models\OrganizationIntegration;
use App\Models\Tenant;
use App\Services\Action1\Action1OrganizationSyncService;
use App\Support\Action1SyncTarget;
use App\Support\IntegrationType;
use App\Support\OrganizationIntegrationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class Action1IntegrationSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_reconciles_endpoints_with_us_base_url(): void
    {
        $euBaseUrl = config('helixdesk_integrations.action1.regions.us');
        Http::fake(function (Request $request) use ($euBaseUrl) {
            $url = $request->url();
            if (str_contains($url, $euBaseUrl.'oauth2/token')) {
                return Http::response(['access_token' => 'fake-token', 'expires_in' => 3600], 200);
            }
            if (str_contains($url, 'installed-software/')) {
                return Http::response(['data' => [[
                    'id' => 'app-1',
                    'name' => '7-Zip',
                    'version' => '23.01',
                ]]], 200);
            }
            if (str_contains($url, 'missing-updates')) {
                return Http::response(['data' => [[
                    'id' => 'KB5036893',
                    'title' => 'Security Update',
                    'severity' => 'Critical',
                ]]], 200);
            }
            if (str_contains($url, $euBaseUrl.'endpoints/managed/action1-org-1')) {
                return Http::response([
                    'data' => [[
                        'id' => 'endpoint-us-1',
                        'name' => 'DESKTOP-US',
                        'serial' => 'SN-US-001',
                        'mac' => 'AA:BB:CC:DD:EE:01',
                        'platform' => 'Windows',
                        'OS_version' => '11',
                    ]],
                ], 200);
            }

            return Http::response(['data' => []], 200);
        });

        $tenant = Tenant::factory()->create();
        $organization = Organization::factory()->create(['tenant_id' => $tenant->id]);
        $integration = $this->createAction1Integration($tenant, $organization, [
            'api_base_url' => $euBaseUrl,
        ]);

        app(Action1OrganizationSyncService::class)->sync(
            $this->action1TargetFromIntegration($tenant, $organization, $integration),
        );

        $asset = Asset::query()
            ->where('tenant_id', $tenant->id)
            ->where('organization_id', $organization->id)
            ->where('name', 'DESKTOP-US')
            ->first();

        $this->assertNotNull($asset);
        $this->assertDatabaseHas('asset_external_identifiers', [
            'asset_id' => $asset->id,
            'provider' => IntegrationType::ACTION1,
            'identifier_type' => AssetExternalIdentifierType::Action1EndpointId->value,
            'external_id' => 'endpoint-us-1',
        ]);
        $this->assertDatabaseHas('asset_software_installations', [
            'asset_id' => $asset->id,
            'provider' => IntegrationType::ACTION1,
            'name' => '7-Zip',
        ]);
        $this->assertDatabaseHas('asset_patch_items', [
            'asset_id' => $asset->id,
            'provider' => IntegrationType::ACTION1,
            'kb_id' => 'KB5036893',
        ]);

        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), $euBaseUrl));
    }

    public function test_sync_uses_eu_base_url_and_group_filter(): void
    {
        $euBaseUrl = config('helixdesk_integrations.action1.regions.eu');

        Http::fake(function (Request $request) use ($euBaseUrl) {
            $url = $request->url();
            if (str_contains($url, $euBaseUrl.'oauth2/token')) {
                return Http::response(['access_token' => 'eu-token', 'expires_in' => 3600], 200);
            }
            if (str_contains($url, $euBaseUrl.'endpoints/groups/action1-org-eu/group-eu/contents')) {
                return Http::response([
                    'data' => [
                        ['id' => 'endpoint-in-group'],
                    ],
                ], 200);
            }
            if (str_contains($url, $euBaseUrl.'endpoints/managed/action1-org-eu')) {
                return Http::response([
                    'data' => [
                        [
                            'id' => 'endpoint-in-group',
                            'name' => 'EU-LAPTOP-GROUP',
                            'serial' => 'SN-EU-001',
                        ],
                        [
                            'id' => 'endpoint-outside-group',
                            'name' => 'EU-LAPTOP-OTHER',
                            'serial' => 'SN-EU-002',
                        ],
                    ],
                ], 200);
            }

            return Http::response(['data' => []], 200);
        });

        $tenant = Tenant::factory()->create();
        $organization = Organization::factory()->create(['tenant_id' => $tenant->id]);
        $integration = $this->createAction1Integration($tenant, $organization, [
            'api_base_url' => $euBaseUrl,
            'action1_organization_id' => 'action1-org-eu',
            'action1_group_id' => 'group-eu',
            'scopes' => [
                'endpoints' => true,
                'software' => false,
                'patches' => false,
                'alerts' => false,
            ],
        ]);

        app(Action1OrganizationSyncService::class)->sync(
            $this->action1TargetFromIntegration($tenant, $organization, $integration),
        );

        $this->assertDatabaseHas('assets', [
            'tenant_id' => $tenant->id,
            'organization_id' => $organization->id,
            'name' => 'EU-LAPTOP-GROUP',
        ]);
        $this->assertDatabaseMissing('assets', [
            'tenant_id' => $tenant->id,
            'organization_id' => $organization->id,
            'name' => 'EU-LAPTOP-OTHER',
        ]);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), $euBaseUrl.'endpoints/groups/action1-org-eu/group-eu/contents'));
    }

    public function test_organization_settings_import_dispatches_action1_job(): void
    {
        Bus::fake();

        $tenant = Tenant::factory()->create();
        $organization = Organization::factory()->create(['tenant_id' => $tenant->id]);
        $integration = $this->createAction1Integration($tenant, $organization, [
            'id' => 'action1-dispatch',
        ]);

        (new OrganizationSettingsImport)->handle();

        Bus::assertDispatched(SyncNextAction1IntegrationJob::class, function (SyncNextAction1IntegrationJob $job) use ($organization): bool {
            return $job->organizationId === (int) $organization->id && $job->integrationId === 'action1-dispatch';
        });
    }

    public function test_sync_next_action1_job_records_failure_on_http_500(): void
    {
        $baseUrl = config('helixdesk_integrations.action1.regions.us');

        Http::fake(function (Request $request) use ($baseUrl) {
            $url = $request->url();
            if (str_contains($url, $baseUrl.'oauth2/token')) {
                return Http::response(['access_token' => 'fake-token', 'expires_in' => 3600], 200);
            }
            if (str_contains($url, $baseUrl.'endpoints/managed/')) {
                return Http::response(['error' => 'internal server error'], 500);
            }

            return Http::response(['data' => []], 200);
        });

        $tenant = Tenant::factory()->create();
        $organization = Organization::factory()->create(['tenant_id' => $tenant->id]);
        $this->createAction1Integration($tenant, $organization, [
            'id' => 'action1-http-500',
        ]);

        (new SyncNextAction1IntegrationJob((int) $organization->id, 'action1-http-500'))
            ->handle(app(Action1OrganizationSyncService::class));

        $integration = OrganizationIntegration::query()->withoutGlobalScopes()->find('action1-http-500');
        $this->assertNotNull($integration);
        $this->assertNotNull($integration->last_sync_error);
        $this->assertStringContainsString('Action1 GET endpoints/managed/', $integration->last_sync_error);
        $this->assertNull($integration->last_sync_at);
    }

    public function test_sync_next_action1_job_records_failure_on_connection_timeout(): void
    {
        $baseUrl = config('helixdesk_integrations.action1.regions.us');

        Http::fake(function (Request $request) use ($baseUrl) {
            $url = $request->url();
            if (str_contains($url, $baseUrl.'oauth2/token')) {
                throw new ConnectionException('cURL error 28: Operation timed out after 120000 milliseconds with 0 bytes received');
            }

            return Http::response(['data' => []], 200);
        });

        $tenant = Tenant::factory()->create();
        $organization = Organization::factory()->create(['tenant_id' => $tenant->id]);
        $this->createAction1Integration($tenant, $organization, [
            'id' => 'action1-timeout',
        ]);

        (new SyncNextAction1IntegrationJob((int) $organization->id, 'action1-timeout'))
            ->handle(app(Action1OrganizationSyncService::class));

        $integration = OrganizationIntegration::query()->withoutGlobalScopes()->find('action1-timeout');
        $this->assertNotNull($integration);
        $this->assertNotNull($integration->last_sync_error);
        $this->assertStringContainsString('Action1 token request connection failed', $integration->last_sync_error);
        $this->assertNull($integration->last_sync_at);
    }

    public function test_sync_next_action1_job_has_configured_timeout(): void
    {
        $job = new SyncNextAction1IntegrationJob(1, 'integration-id');

        $this->assertSame(
            max(60, (int) config('helixdesk_integrations.action1.sync_job_timeout_seconds', 600)),
            $job->timeout,
        );
        $this->assertSame(1, $job->tries);
    }

    public function test_patch_compliance_summary_reflects_missing_updates(): void
    {
        $baseUrl = config('helixdesk_integrations.action1.regions.us');

        Http::fake(function (Request $request) {
            $url = $request->url();
            if (str_contains($url, 'oauth2/token')) {
                return Http::response(['access_token' => 'fake-token'], 200);
            }
            if (str_contains($url, 'missing-updates')) {
                return Http::response(['data' => [
                    ['id' => 'KB1', 'title' => 'Critical patch', 'severity' => 'Critical'],
                    ['id' => 'KB2', 'title' => 'Other patch', 'severity' => 'Moderate'],
                ]], 200);
            }
            if (str_contains($url, 'endpoints/managed/')) {
                return Http::response(['data' => [['id' => 'ep-1', 'name' => 'PATCH-HOST', 'serial' => 'SN-P-1']]], 200);
            }

            return Http::response(['data' => []], 200);
        });

        $tenant = Tenant::factory()->create();
        $organization = Organization::factory()->create(['tenant_id' => $tenant->id]);
        $integration = $this->createAction1Integration($tenant, $organization, [
            'scopes' => [
                'endpoints' => true,
                'software' => false,
                'patches' => true,
                'alerts' => false,
            ],
        ]);

        app(Action1OrganizationSyncService::class)->sync(
            $this->action1TargetFromIntegration($tenant, $organization, $integration),
        );

        $asset = Asset::query()->where('name', 'PATCH-HOST')->first();
        $this->assertNotNull($asset);

        $this->assertSame(2, AssetPatchItem::query()->where('asset_id', $asset->id)->count());
        $this->assertDatabaseHas('asset_patch_status', [
            'asset_id' => $asset->id,
            'provider' => IntegrationType::ACTION1,
            'missing_critical_count' => 1,
            'missing_other_count' => 1,
            'compliance_status' => AssetPatchComplianceStatus::CriticalMissing->value,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createAction1Integration(Tenant $tenant, Organization $organization, array $overrides = []): OrganizationIntegration
    {
        $config = [
            'action1_organization_id' => $overrides['action1_organization_id'] ?? 'action1-org-1',
            'action1_group_id' => $overrides['action1_group_id'] ?? '',
            'scopes' => $overrides['scopes'] ?? [
                'endpoints' => true,
                'software' => true,
                'patches' => true,
                'alerts' => false,
            ],
        ];

        return OrganizationIntegration::query()->create([
            'id' => $overrides['id'] ?? (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'organization_id' => $organization->id,
            'type' => IntegrationType::ACTION1,
            'enabled' => true,
            'config' => $config,
            'credentials' => [
                'client_id' => 'client-id@action1.com',
                'client_secret' => 'secret',
            ],
            'api_base_url' => $overrides['api_base_url'] ?? config('helixdesk_integrations.action1.default_api_base_url'),
            'scope_key' => OrganizationIntegration::resolveScopeKey(IntegrationType::ACTION1, $config),
        ]);
    }

    private function action1TargetFromIntegration(Tenant $tenant, Organization $organization, OrganizationIntegration $integration): Action1SyncTarget
    {
        $entry = OrganizationIntegrationSettings::findEntryForOrganization($organization, (string) $integration->getKey());
        $config = is_array($entry['config'] ?? null) ? $entry['config'] : [];

        return new Action1SyncTarget(
            tenantId: (int) $tenant->id,
            organizationId: (int) $organization->id,
            integrationId: (string) $integration->getKey(),
            config: $config,
            credentials: $integration->mergedCredentials(),
            apiBaseUrl: $integration->api_base_url,
        );
    }
}
