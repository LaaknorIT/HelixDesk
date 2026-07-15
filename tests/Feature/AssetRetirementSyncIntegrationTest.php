<?php

namespace Tests\Feature;

use App\Enums\AssetExternalIdentifierType;
use App\Models\Asset;
use App\Models\AssetExternalIdentifier;
use App\Models\AssetType;
use App\Models\Organization;
use App\Models\OrganizationIntegration;
use App\Models\Tenant;
use App\Services\Action1\Action1OrganizationSyncService;
use App\Support\Action1SyncTarget;
use App\Support\IntegrationType;
use App\Support\TenantIntegrationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AssetRetirementSyncIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_action1_sync_retires_asset_when_auto_retire_enabled_and_identifiers_stale(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-14 12:00:00', 'UTC'));

        $baseUrl = config('helixdesk_integrations.action1.regions.us');
        Http::fake(function (Request $request) use ($baseUrl) {
            $url = $request->url();
            if (str_contains($url, $baseUrl.'oauth2/token')) {
                return Http::response(['access_token' => 'fake-token', 'expires_in' => 3600], 200);
            }
            if (str_contains($url, $baseUrl.'endpoints/managed/action1-org-1')) {
                return Http::response(['data' => []], 200);
            }

            return Http::response(['data' => []], 200);
        });

        $tenant = Tenant::factory()->create([
            'integration_settings' => [
                TenantIntegrationSettings::AUTO_RETIRE_ASSETS_WHEN_ALL_PROVIDERS_DROP => true,
                TenantIntegrationSettings::RETIREMENT_GRACE_DAYS => 30,
            ],
        ]);
        $organization = Organization::factory()->create(['tenant_id' => $tenant->id]);
        $integration = OrganizationIntegration::factory()->create([
            'tenant_id' => $tenant->id,
            'organization_id' => $organization->id,
            'type' => IntegrationType::ACTION1,
            'config' => [
                'action1_organization_id' => 'action1-org-1',
                'scopes' => ['endpoints' => true],
            ],
            'api_base_url' => $baseUrl,
        ]);
        $assetType = AssetType::factory()->create(['tenant_id' => $tenant->id]);
        $asset = Asset::factory()->create([
            'tenant_id' => $tenant->id,
            'organization_id' => $organization->id,
            'asset_type_id' => $assetType->id,
            'lifecycle_status' => 'in_use',
        ]);

        AssetExternalIdentifier::query()->create([
            'tenant_id' => $tenant->id,
            'asset_id' => $asset->id,
            'organization_id' => $organization->id,
            'organization_integration_id' => $integration->id,
            'provider' => IntegrationType::ACTION1,
            'identifier_type' => AssetExternalIdentifierType::Action1EndpointId,
            'external_id' => 'endpoint-stale',
            'last_seen_at' => now()->subDays(45),
        ]);

        app(Action1OrganizationSyncService::class)->sync(
            new Action1SyncTarget(
                tenantId: $tenant->id,
                organizationId: $organization->id,
                integrationId: (string) $integration->id,
                config: $integration->config ?? [],
                credentials: [
                    'client_id' => 'client-id@action1.com',
                    'client_secret' => 'secret',
                ],
                apiBaseUrl: $baseUrl,
            ),
        );

        $this->assertSame('retired', $asset->fresh()->lifecycle_status);

        Carbon::setTestNow();
    }

    public function test_action1_sync_does_not_retire_when_grace_period_not_elapsed(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-14 12:00:00', 'UTC'));

        $baseUrl = config('helixdesk_integrations.action1.regions.us');
        Http::fake(function (Request $request) use ($baseUrl) {
            $url = $request->url();
            if (str_contains($url, $baseUrl.'oauth2/token')) {
                return Http::response(['access_token' => 'fake-token', 'expires_in' => 3600], 200);
            }

            return Http::response(['data' => []], 200);
        });

        $tenant = Tenant::factory()->create([
            'integration_settings' => [
                TenantIntegrationSettings::AUTO_RETIRE_ASSETS_WHEN_ALL_PROVIDERS_DROP => true,
                TenantIntegrationSettings::RETIREMENT_GRACE_DAYS => 30,
            ],
        ]);
        $organization = Organization::factory()->create(['tenant_id' => $tenant->id]);
        $integration = OrganizationIntegration::factory()->create([
            'tenant_id' => $tenant->id,
            'organization_id' => $organization->id,
            'type' => IntegrationType::ACTION1,
            'config' => [
                'action1_organization_id' => 'action1-org-1',
                'scopes' => ['endpoints' => true],
            ],
            'api_base_url' => $baseUrl,
        ]);
        $assetType = AssetType::factory()->create(['tenant_id' => $tenant->id]);
        $asset = Asset::factory()->create([
            'tenant_id' => $tenant->id,
            'organization_id' => $organization->id,
            'asset_type_id' => $assetType->id,
            'lifecycle_status' => 'in_use',
        ]);

        AssetExternalIdentifier::query()->create([
            'tenant_id' => $tenant->id,
            'asset_id' => $asset->id,
            'organization_id' => $organization->id,
            'organization_integration_id' => $integration->id,
            'provider' => IntegrationType::ACTION1,
            'identifier_type' => AssetExternalIdentifierType::Action1EndpointId,
            'external_id' => 'endpoint-recent',
            'last_seen_at' => now()->subDays(10),
        ]);

        app(Action1OrganizationSyncService::class)->sync(
            new Action1SyncTarget(
                tenantId: $tenant->id,
                organizationId: $organization->id,
                integrationId: (string) $integration->id,
                config: $integration->config ?? [],
                credentials: [
                    'client_id' => 'client-id@action1.com',
                    'client_secret' => 'secret',
                ],
                apiBaseUrl: $baseUrl,
            ),
        );

        $this->assertSame('in_use', $asset->fresh()->lifecycle_status);

        Carbon::setTestNow();
    }
}
