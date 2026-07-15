<?php

namespace Tests\Feature;

use App\Enums\AssetExternalIdentifierType;
use App\Models\Asset;
use App\Models\AssetExternalIdentifier;
use App\Models\AssetType;
use App\Models\MonitoringEvent;
use App\Models\MonitoringEventSource;
use App\Models\Organization;
use App\Models\OrganizationIntegration;
use App\Models\Tenant;
use App\Services\Action1\Action1OrganizationSyncService;
use App\Services\MicrosoftEntra\MicrosoftEntraOrganizationSyncService;
use App\Services\MonitoringEventAssetLinker;
use App\Support\Action1SyncTarget;
use App\Support\EntraSyncTarget;
use App\Support\IntegrationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MonitoringEventAssetLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_linker_attaches_asset_by_action1_endpoint_id(): void
    {
        [$tenant, $organization, $integration, $asset] = $this->createAssetWithEndpoint('endpoint-42');

        $source = MonitoringEventSource::query()->create([
            'tenant_id' => $tenant->id,
            'slug' => 'action1-test',
            'name' => 'Action1 test',
            'provider' => IntegrationType::ACTION1,
        ]);

        $event = MonitoringEvent::query()->create([
            'tenant_id' => $tenant->id,
            'monitoring_event_source_id' => $source->id,
            'external_reference' => 'alert-1',
            'severity' => 'high',
            'message' => 'Disk space low',
            'status' => 'open',
            'deduplication_key' => 'action1:test:alert-1',
            'configuration_json' => ['endpoint_id' => 'endpoint-42'],
        ]);

        app(MonitoringEventAssetLinker::class)->linkByAction1EndpointId($event, 'endpoint-42');

        $this->assertTrue($event->assets()->whereKey($asset->id)->exists());
    }

    public function test_action1_alert_sync_links_monitoring_event_to_asset(): void
    {
        $baseUrl = config('helixdesk_integrations.action1.regions.us');
        Http::fake(function (Request $request) use ($baseUrl) {
            $url = $request->url();
            if (str_contains($url, $baseUrl.'oauth2/token')) {
                return Http::response(['access_token' => 'fake-token', 'expires_in' => 3600], 200);
            }
            if (str_contains($url, 'audit/events')) {
                return Http::response(['data' => [[
                    'id' => 'alert-sync-1',
                    'title' => 'Endpoint offline',
                    'severity' => 'high',
                    'status' => 'open',
                    'endpoint_id' => 'endpoint-alert',
                ]]], 200);
            }

            return Http::response(['data' => []], 200);
        });

        [$tenant, $organization, $integration, $asset] = $this->createAssetWithEndpoint('endpoint-alert');

        app(Action1OrganizationSyncService::class)->sync(
            new Action1SyncTarget(
                tenantId: $tenant->id,
                organizationId: $organization->id,
                integrationId: (string) $integration->id,
                config: [
                    'action1_organization_id' => 'action1-org-1',
                    'scopes' => ['alerts' => true],
                ],
                credentials: [
                    'client_id' => 'client-id@action1.com',
                    'client_secret' => 'secret',
                ],
                apiBaseUrl: $baseUrl,
            ),
        );

        $event = MonitoringEvent::query()
            ->where('tenant_id', $tenant->id)
            ->where('external_reference', 'alert-sync-1')
            ->first();

        $this->assertNotNull($event);
        $this->assertTrue($event->assets()->whereKey($asset->id)->exists());
    }

    public function test_linker_attaches_asset_by_entra_device_state_shape(): void
    {
        $tenant = Tenant::factory()->create();
        $organization = Organization::factory()->create(['tenant_id' => $tenant->id]);
        $integration = OrganizationIntegration::factory()->create([
            'tenant_id' => $tenant->id,
            'organization_id' => $organization->id,
        ]);
        $assetType = AssetType::factory()->create(['tenant_id' => $tenant->id]);
        $asset = Asset::factory()->create([
            'tenant_id' => $tenant->id,
            'organization_id' => $organization->id,
            'asset_type_id' => $assetType->id,
        ]);

        AssetExternalIdentifier::query()->create([
            'tenant_id' => $tenant->id,
            'asset_id' => $asset->id,
            'organization_id' => $organization->id,
            'organization_integration_id' => $integration->id,
            'provider' => IntegrationType::MICROSOFT_ENTRA,
            'identifier_type' => AssetExternalIdentifierType::EntraAzureAdDeviceId,
            'external_id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'last_seen_at' => now(),
        ]);

        $source = MonitoringEventSource::query()->create([
            'tenant_id' => $tenant->id,
            'slug' => 'entra-test',
            'name' => 'Entra test',
            'provider' => IntegrationType::MICROSOFT_ENTRA,
        ]);

        $event = MonitoringEvent::query()->create([
            'tenant_id' => $tenant->id,
            'monitoring_event_source_id' => $source->id,
            'external_reference' => 'inc-device-state',
            'severity' => 'high',
            'message' => 'Malware detected',
            'status' => 'open',
            'deduplication_key' => 'entra:test:inc-device-state',
        ]);

        app(MonitoringEventAssetLinker::class)->linkEntraSecurityWorkItem($event, [
            'deviceState' => [
                'azureADDeviceId' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            ],
        ]);

        $this->assertTrue($event->assets()->whereKey($asset->id)->exists());
    }

    public function test_entra_security_sync_links_asset_from_device_state(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();
            if (str_contains($url, 'login.microsoftonline.com')) {
                return Http::response(['access_token' => 'fake-token', 'expires_in' => 3600], 200);
            }
            if (str_contains($url, 'graph.microsoft.com/v1.0/security/work_items')) {
                return Http::response([
                    'value' => [[
                        'id' => 'inc-device-state-sync',
                        'displayName' => 'Suspicious process',
                        'status' => 'active',
                        'severity' => 'high',
                        'deviceState' => [
                            'azureADDeviceId' => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
                        ],
                    ]],
                ], 200);
            }

            return Http::response(['value' => []], 200);
        });

        $tenant = Tenant::factory()->create();
        $organization = Organization::factory()->create(['tenant_id' => $tenant->id]);
        $integration = OrganizationIntegration::factory()->create([
            'tenant_id' => $tenant->id,
            'organization_id' => $organization->id,
            'type' => IntegrationType::MICROSOFT_ENTRA,
            'config' => [
                'azure_tenant_id' => 'tenant-guid',
                'application_client_id' => 'client-guid',
                'scopes' => [
                    'users' => false,
                    'departments' => false,
                    'computers' => false,
                    'security_work_items' => true,
                ],
            ],
            'credentials' => ['client_secret' => 'secret'],
        ]);
        $assetType = AssetType::factory()->create(['tenant_id' => $tenant->id]);
        $asset = Asset::factory()->create([
            'tenant_id' => $tenant->id,
            'organization_id' => $organization->id,
            'asset_type_id' => $assetType->id,
        ]);

        AssetExternalIdentifier::query()->create([
            'tenant_id' => $tenant->id,
            'asset_id' => $asset->id,
            'organization_id' => $organization->id,
            'organization_integration_id' => $integration->id,
            'provider' => IntegrationType::MICROSOFT_ENTRA,
            'identifier_type' => AssetExternalIdentifierType::EntraAzureAdDeviceId,
            'external_id' => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
            'last_seen_at' => now(),
        ]);

        app(MicrosoftEntraOrganizationSyncService::class)->sync(
            new EntraSyncTarget(
                tenantId: $tenant->id,
                organizationId: $organization->id,
                integrationId: (string) $integration->id,
                config: array_merge($integration->config ?? [], [
                    'client_secret' => $integration->credentials['client_secret'] ?? 'secret',
                ]),
            ),
        );

        $event = MonitoringEvent::query()
            ->where('tenant_id', $tenant->id)
            ->where('external_reference', 'inc-device-state-sync')
            ->first();

        $this->assertNotNull($event);
        $this->assertTrue($event->assets()->whereKey($asset->id)->exists());
    }

    /**
     * @return array{0: Tenant, 1: Organization, 2: OrganizationIntegration, 3: Asset}
     */
    private function createAssetWithEndpoint(string $endpointId): array
    {
        $tenant = Tenant::factory()->create();
        $organization = Organization::factory()->create(['tenant_id' => $tenant->id]);
        $integration = OrganizationIntegration::factory()->create([
            'tenant_id' => $tenant->id,
            'organization_id' => $organization->id,
            'type' => IntegrationType::ACTION1,
            'api_base_url' => config('helixdesk_integrations.action1.regions.us'),
        ]);
        $assetType = AssetType::factory()->create(['tenant_id' => $tenant->id]);
        $asset = Asset::factory()->create([
            'tenant_id' => $tenant->id,
            'organization_id' => $organization->id,
            'asset_type_id' => $assetType->id,
        ]);

        AssetExternalIdentifier::query()->create([
            'tenant_id' => $tenant->id,
            'asset_id' => $asset->id,
            'organization_id' => $organization->id,
            'organization_integration_id' => $integration->id,
            'provider' => IntegrationType::ACTION1,
            'identifier_type' => AssetExternalIdentifierType::Action1EndpointId,
            'external_id' => $endpointId,
            'last_seen_at' => now(),
        ]);

        return [$tenant, $organization, $integration, $asset];
    }
}
