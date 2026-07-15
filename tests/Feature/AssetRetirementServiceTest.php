<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AssetExternalIdentifier;
use App\Models\AssetType;
use App\Models\Organization;
use App\Models\OrganizationIntegration;
use App\Models\Tenant;
use App\Services\AssetRetirementService;
use App\Support\IntegrationType;
use App\Support\TenantIntegrationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class AssetRetirementServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_does_not_retire_when_auto_retire_disabled(): void
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
            'lifecycle_status' => 'in_use',
        ]);

        AssetExternalIdentifier::query()->create([
            'tenant_id' => $tenant->id,
            'asset_id' => $asset->id,
            'organization_id' => $organization->id,
            'organization_integration_id' => $integration->id,
            'provider' => IntegrationType::ACTION1,
            'identifier_type' => 'action1_endpoint_id',
            'external_id' => 'endpoint-stale',
            'last_seen_at' => now()->subDays(60),
        ]);

        $service = app(AssetRetirementService::class);
        $this->assertTrue($service->evaluateAsset($asset->fresh()));
        $this->assertSame('in_use', $asset->fresh()->lifecycle_status);

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'assets',
            'subject_type' => $asset->getMorphClass(),
            'subject_id' => $asset->id,
            'event' => 'retirement_suggested',
        ]);
    }

    public function test_retires_asset_when_auto_retire_enabled_and_identifiers_stale(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-14 12:00:00', 'UTC'));

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
            'identifier_type' => 'action1_endpoint_id',
            'external_id' => 'endpoint-stale',
            'last_seen_at' => now()->subDays(45),
        ]);

        $service = app(AssetRetirementService::class);
        $this->assertTrue($service->evaluateAsset($asset->fresh()));
        $this->assertSame('retired', $asset->fresh()->lifecycle_status);

        $activity = Activity::query()
            ->where('log_name', 'assets')
            ->where('subject_type', $asset->getMorphClass())
            ->where('subject_id', $asset->id)
            ->where('event', 'retired')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame(
            'retired',
            data_get($activity->properties, 'attribute_changes.attributes.lifecycle_status')
        );
        $this->assertSame(
            'in_use',
            data_get($activity->properties, 'attribute_changes.old.lifecycle_status')
        );

        Carbon::setTestNow();
    }

    public function test_logs_retirement_suggestion_when_auto_retire_disabled_and_identifiers_stale(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-14 12:00:00', 'UTC'));

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
            'lifecycle_status' => 'in_use',
        ]);

        AssetExternalIdentifier::query()->create([
            'tenant_id' => $tenant->id,
            'asset_id' => $asset->id,
            'organization_id' => $organization->id,
            'organization_integration_id' => $integration->id,
            'provider' => IntegrationType::ACTION1,
            'identifier_type' => 'action1_endpoint_id',
            'external_id' => 'endpoint-stale',
            'last_seen_at' => now()->subDays(45),
        ]);

        $service = app(AssetRetirementService::class);
        $this->assertTrue($service->evaluateAsset($asset->fresh()));
        $this->assertSame('in_use', $asset->fresh()->lifecycle_status);

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'assets',
            'subject_type' => $asset->getMorphClass(),
            'subject_id' => $asset->id,
            'event' => 'retirement_suggested',
        ]);

        $this->assertFalse($service->evaluateAsset($asset->fresh()));

        Carbon::setTestNow();
    }

    public function test_evaluate_tenant_suggests_retirement_when_auto_retire_disabled(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-14 12:00:00', 'UTC'));

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
            'lifecycle_status' => 'in_use',
        ]);

        AssetExternalIdentifier::query()->create([
            'tenant_id' => $tenant->id,
            'asset_id' => $asset->id,
            'organization_id' => $organization->id,
            'organization_integration_id' => $integration->id,
            'provider' => IntegrationType::ACTION1,
            'identifier_type' => 'action1_endpoint_id',
            'external_id' => 'endpoint-stale',
            'last_seen_at' => now()->subDays(45),
        ]);

        $service = app(AssetRetirementService::class);
        $this->assertSame(1, $service->evaluateTenant($tenant));
        $this->assertSame('in_use', $asset->fresh()->lifecycle_status);

        Carbon::setTestNow();
    }
}
