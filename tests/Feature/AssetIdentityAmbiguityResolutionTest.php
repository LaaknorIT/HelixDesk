<?php

namespace Tests\Feature;

use App\Data\AssetProviderSnapshot;
use App\Enums\AssetExternalIdentifierType;
use App\Enums\AssetIdentityAmbiguityStatus;
use App\Enums\AssetIdentityResolutionStatus;
use App\Models\Asset;
use App\Models\AssetIdentityAmbiguity;
use App\Models\AssetType;
use App\Models\Organization;
use App\Models\OrganizationIntegration;
use App\Models\Tenant;
use App\Services\AssetIdentityAmbiguityResolutionService;
use App\Services\AssetReconciliationService;
use App\Support\IntegrationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class AssetIdentityAmbiguityResolutionTest extends TestCase
{
    use RefreshDatabase;

    private AssetIdentityAmbiguityResolutionService $resolutionService;

    private AssetReconciliationService $reconciliationService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolutionService = app(AssetIdentityAmbiguityResolutionService::class);
        $this->reconciliationService = app(AssetReconciliationService::class);
    }

    public function test_link_to_asset_resolves_ambiguity_and_links_identifiers(): void
    {
        [$tenant, $organization, $integration, $ambiguity, $first, $second] = $this->createAmbiguityFixtures();

        $resolved = $this->resolutionService->linkToAsset($ambiguity, $first);

        $this->assertSame((int) $first->id, (int) $resolved->id);
        $this->assertSame(AssetIdentityAmbiguityStatus::Resolved, $ambiguity->fresh()->status);
        $this->assertSame((int) $first->id, (int) $ambiguity->fresh()->resolved_asset_id);
        $this->assertSame(AssetIdentityResolutionStatus::Resolved, $first->fresh()->identity_resolution_status);
        $this->assertSame(AssetIdentityResolutionStatus::Resolved, $second->fresh()->identity_resolution_status);

        $this->assertDatabaseHas('asset_external_identifiers', [
            'tenant_id' => $tenant->id,
            'asset_id' => $first->id,
            'provider' => IntegrationType::ACTION1,
            'identifier_type' => AssetExternalIdentifierType::Action1EndpointId->value,
            'external_id' => 'endpoint-ambiguous',
        ]);
    }

    public function test_create_new_asset_from_ambiguity(): void
    {
        [$tenant, $organization, $integration, $ambiguity, $first, $second] = $this->createAmbiguityFixtures();

        $asset = $this->resolutionService->createNewAsset($ambiguity);

        $this->assertNotNull($asset->getKey());
        $this->assertSame('Ambiguous endpoint', $asset->name);
        $this->assertSame(AssetIdentityAmbiguityStatus::Resolved, $ambiguity->fresh()->status);
        $this->assertSame((int) $asset->id, (int) $ambiguity->fresh()->resolved_asset_id);
        $this->assertSame(3, Asset::query()->withoutGlobalScopes()->count());
    }

    public function test_dismiss_ambiguity_clears_candidate_status_when_no_other_open_ambiguities(): void
    {
        [$tenant, $organization, $integration, $ambiguity, $first, $second] = $this->createAmbiguityFixtures();

        $this->resolutionService->dismiss($ambiguity);

        $this->assertSame(AssetIdentityAmbiguityStatus::Dismissed, $ambiguity->fresh()->status);
        $this->assertNull($ambiguity->fresh()->resolved_asset_id);
        $this->assertSame(AssetIdentityResolutionStatus::Resolved, $first->fresh()->identity_resolution_status);
        $this->assertSame(AssetIdentityResolutionStatus::Resolved, $second->fresh()->identity_resolution_status);
    }

    public function test_cannot_resolve_already_resolved_ambiguity(): void
    {
        [$tenant, $organization, $integration, $ambiguity, $first] = $this->createAmbiguityFixtures();
        $ambiguity->status = AssetIdentityAmbiguityStatus::Resolved;
        $ambiguity->resolved_asset_id = $first->id;
        $ambiguity->save();

        $this->expectException(InvalidArgumentException::class);
        $this->resolutionService->linkToAsset($ambiguity, $first);
    }

    /**
     * @return array{0: Tenant, 1: Organization, 2: OrganizationIntegration, 3: AssetIdentityAmbiguity, 4: Asset, 5: Asset}
     */
    private function createAmbiguityFixtures(): array
    {
        $tenant = Tenant::factory()->create();
        $organization = Organization::factory()->create(['tenant_id' => $tenant->id]);
        $integration = OrganizationIntegration::factory()->create([
            'tenant_id' => $tenant->id,
            'organization_id' => $organization->id,
            'type' => IntegrationType::ACTION1,
        ]);
        $assetType = AssetType::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Computer']);

        $first = Asset::factory()->create([
            'tenant_id' => $tenant->id,
            'organization_id' => $organization->id,
            'asset_type_id' => $assetType->id,
            'serial_number' => 'SN-DUP',
            'primary_hostname' => 'dup-host',
            'identity_resolution_status' => AssetIdentityResolutionStatus::Ambiguous,
        ]);
        $second = Asset::factory()->create([
            'tenant_id' => $tenant->id,
            'organization_id' => $organization->id,
            'asset_type_id' => $assetType->id,
            'serial_number' => 'SN-DUP',
            'primary_hostname' => 'dup-host',
            'identity_resolution_status' => AssetIdentityResolutionStatus::Ambiguous,
        ]);

        $result = $this->reconciliationService->reconcile(new AssetProviderSnapshot(
            tenantId: $tenant->id,
            organizationId: $organization->id,
            organizationIntegrationId: (string) $integration->id,
            provider: IntegrationType::ACTION1,
            identifiers: [
                ['type' => AssetExternalIdentifierType::SerialNumber, 'value' => 'SN-DUP'],
                ['type' => AssetExternalIdentifierType::Hostname, 'value' => 'dup-host'],
                ['type' => AssetExternalIdentifierType::Action1EndpointId, 'value' => 'endpoint-ambiguous'],
            ],
            attributes: ['name' => 'Ambiguous endpoint'],
        ));

        $ambiguity = $result->ambiguity;
        $this->assertNotNull($ambiguity);

        return [$tenant, $organization, $integration, $ambiguity, $first, $second];
    }
}
