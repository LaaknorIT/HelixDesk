<?php

namespace Tests\Feature;

use App\Data\AssetProviderSnapshot;
use App\Enums\AssetExternalIdentifierType;
use App\Enums\AssetIdentityAmbiguityStatus;
use App\Enums\AssetIdentityResolutionStatus;
use App\Models\Asset;
use App\Models\AssetExternalIdentifier;
use App\Models\AssetIdentityAmbiguity;
use App\Models\AssetType;
use App\Models\Organization;
use App\Models\OrganizationIntegration;
use App\Models\Tenant;
use App\Services\AssetReconciliationService;
use App\Support\IntegrationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssetReconciliationServiceTest extends TestCase
{
    use RefreshDatabase;

    private AssetReconciliationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(AssetReconciliationService::class);
    }

    public function test_serial_alone_does_not_match_existing_asset(): void
    {
        [$tenant, $organization, $integration] = $this->createIntegrationFixtures();

        $assetType = AssetType::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Computer']);
        Asset::factory()->create([
            'tenant_id' => $tenant->id,
            'organization_id' => $organization->id,
            'asset_type_id' => $assetType->id,
            'serial_number' => 'SN-12345',
            'primary_hostname' => 'other-host',
        ]);

        $result = $this->service->reconcile(new AssetProviderSnapshot(
            tenantId: $tenant->id,
            organizationId: $organization->id,
            organizationIntegrationId: (string) $integration->id,
            provider: IntegrationType::ACTION1,
            identifiers: [
                ['type' => AssetExternalIdentifierType::SerialNumber, 'value' => 'SN-12345'],
                ['type' => AssetExternalIdentifierType::Action1EndpointId, 'value' => 'endpoint-1'],
            ],
            attributes: [
                'name' => 'New endpoint',
            ],
        ));

        $this->assertTrue($result->created);
        $this->assertNotNull($result->asset);
        $this->assertSame(2, Asset::query()->withoutGlobalScopes()->count());
    }

    public function test_serial_and_hostname_match_existing_asset(): void
    {
        [$tenant, $organization, $integration] = $this->createIntegrationFixtures();

        $assetType = AssetType::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Computer']);
        $existing = Asset::factory()->create([
            'tenant_id' => $tenant->id,
            'organization_id' => $organization->id,
            'asset_type_id' => $assetType->id,
            'serial_number' => 'SN-12345',
            'primary_hostname' => 'workstation-a',
            'name' => 'Workstation A',
        ]);

        $result = $this->service->reconcile(new AssetProviderSnapshot(
            tenantId: $tenant->id,
            organizationId: $organization->id,
            organizationIntegrationId: (string) $integration->id,
            provider: IntegrationType::ACTION1,
            identifiers: [
                ['type' => AssetExternalIdentifierType::SerialNumber, 'value' => 'SN-12345'],
                ['type' => AssetExternalIdentifierType::Hostname, 'value' => 'workstation-a.example.test'],
                ['type' => AssetExternalIdentifierType::Action1EndpointId, 'value' => 'endpoint-2'],
            ],
            attributes: [
                'name' => 'Workstation A (Action1)',
            ],
        ));

        $this->assertTrue($result->matched);
        $this->assertFalse($result->created);
        $this->assertSame((int) $existing->id, (int) $result->asset?->id);
        $this->assertDatabaseHas('asset_external_identifiers', [
            'asset_id' => $existing->id,
            'provider' => IntegrationType::ACTION1,
            'identifier_type' => AssetExternalIdentifierType::Action1EndpointId->value,
            'external_id' => 'endpoint-2',
        ]);
    }

    public function test_ambiguity_is_flagged_when_multiple_candidates_match(): void
    {
        [$tenant, $organization, $integration] = $this->createIntegrationFixtures();

        $assetType = AssetType::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Computer']);
        $first = Asset::factory()->create([
            'tenant_id' => $tenant->id,
            'organization_id' => $organization->id,
            'asset_type_id' => $assetType->id,
            'serial_number' => 'SN-DUP',
            'primary_hostname' => 'dup-host',
        ]);
        $second = Asset::factory()->create([
            'tenant_id' => $tenant->id,
            'organization_id' => $organization->id,
            'asset_type_id' => $assetType->id,
            'serial_number' => 'SN-DUP',
            'primary_hostname' => 'dup-host',
        ]);

        $result = $this->service->reconcile(new AssetProviderSnapshot(
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

        $this->assertTrue($result->ambiguous);
        $this->assertNull($result->asset);
        $this->assertNotNull($result->ambiguity);
        $this->assertSame(AssetIdentityAmbiguityStatus::Open, $result->ambiguity->status);

        $first->refresh();
        $second->refresh();
        $this->assertSame(AssetIdentityResolutionStatus::Ambiguous, $first->identity_resolution_status);
        $this->assertSame(AssetIdentityResolutionStatus::Ambiguous, $second->identity_resolution_status);

        $this->assertSame(1, AssetIdentityAmbiguity::query()->count());
        $this->assertEqualsCanonicalizing(
            [(int) $first->id, (int) $second->id],
            $result->ambiguity->candidate_asset_ids,
        );
    }

    public function test_provider_external_id_hard_match_links_existing_asset(): void
    {
        [$tenant, $organization, $integration] = $this->createIntegrationFixtures();

        $assetType = AssetType::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Computer']);
        $existing = Asset::factory()->create([
            'tenant_id' => $tenant->id,
            'organization_id' => $organization->id,
            'asset_type_id' => $assetType->id,
            'name' => 'Existing endpoint',
        ]);

        AssetExternalIdentifier::query()->create([
            'tenant_id' => $tenant->id,
            'asset_id' => $existing->id,
            'organization_id' => $organization->id,
            'organization_integration_id' => $integration->id,
            'provider' => IntegrationType::ACTION1,
            'identifier_type' => AssetExternalIdentifierType::Action1EndpointId,
            'external_id' => 'endpoint-known',
            'is_primary' => true,
            'last_seen_at' => now(),
        ]);

        $result = $this->service->reconcile(new AssetProviderSnapshot(
            tenantId: $tenant->id,
            organizationId: $organization->id,
            organizationIntegrationId: (string) $integration->id,
            provider: IntegrationType::ACTION1,
            identifiers: [
                ['type' => AssetExternalIdentifierType::Action1EndpointId, 'value' => 'endpoint-known'],
            ],
            attributes: ['name' => 'Updated endpoint name'],
        ));

        $this->assertTrue($result->matched);
        $this->assertSame((int) $existing->id, (int) $result->asset?->id);
    }

    /**
     * @return array{0: Tenant, 1: Organization, 2: OrganizationIntegration}
     */
    private function createIntegrationFixtures(): array
    {
        $tenant = Tenant::factory()->create();
        $organization = Organization::factory()->create(['tenant_id' => $tenant->id]);
        $integration = OrganizationIntegration::factory()->create([
            'tenant_id' => $tenant->id,
            'organization_id' => $organization->id,
            'type' => IntegrationType::ACTION1,
        ]);

        return [$tenant, $organization, $integration];
    }
}
