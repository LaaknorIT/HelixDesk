<?php

namespace Tests\Feature;

use App\Enums\AssetExternalIdentifierType;
use App\Models\Asset;
use App\Models\AssetExternalIdentifier;
use App\Models\AssetType;
use App\Models\Organization;
use App\Models\OrganizationIntegration;
use App\Models\Tenant;
use App\Support\IntegrationType;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrganizationIntegrationModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_organization_integration_relationships(): void
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

        $identifier = AssetExternalIdentifier::query()->create([
            'tenant_id' => $tenant->id,
            'asset_id' => $asset->id,
            'organization_id' => $organization->id,
            'organization_integration_id' => $integration->id,
            'provider' => IntegrationType::ACTION1,
            'identifier_type' => AssetExternalIdentifierType::Action1EndpointId,
            'external_id' => 'endpoint-1',
            'is_primary' => true,
            'last_seen_at' => now(),
        ]);

        $this->assertTrue($integration->organization->is($organization));
        $this->assertTrue($integration->tenant->is($tenant));
        $this->assertTrue($organization->organizationIntegrations->contains($integration));
        $this->assertTrue($asset->externalIdentifiers->contains($identifier));
        $this->assertTrue($identifier->organizationIntegration->is($integration));
    }

    public function test_external_identifier_unique_constraint_prevents_duplicate_mapping(): void
    {
        $tenant = Tenant::factory()->create();
        $organization = Organization::factory()->create(['tenant_id' => $tenant->id]);
        $integration = OrganizationIntegration::factory()->create([
            'tenant_id' => $tenant->id,
            'organization_id' => $organization->id,
        ]);
        $assetType = AssetType::factory()->create(['tenant_id' => $tenant->id]);
        $firstAsset = Asset::factory()->create([
            'tenant_id' => $tenant->id,
            'organization_id' => $organization->id,
            'asset_type_id' => $assetType->id,
        ]);
        $secondAsset = Asset::factory()->create([
            'tenant_id' => $tenant->id,
            'organization_id' => $organization->id,
            'asset_type_id' => $assetType->id,
        ]);

        AssetExternalIdentifier::query()->create([
            'tenant_id' => $tenant->id,
            'asset_id' => $firstAsset->id,
            'organization_id' => $organization->id,
            'organization_integration_id' => $integration->id,
            'provider' => IntegrationType::ACTION1,
            'identifier_type' => AssetExternalIdentifierType::Action1EndpointId,
            'external_id' => 'shared-endpoint-id',
            'is_primary' => true,
            'last_seen_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        AssetExternalIdentifier::query()->create([
            'tenant_id' => $tenant->id,
            'asset_id' => $secondAsset->id,
            'organization_id' => $organization->id,
            'organization_integration_id' => $integration->id,
            'provider' => IntegrationType::ACTION1,
            'identifier_type' => AssetExternalIdentifierType::Action1EndpointId,
            'external_id' => 'shared-endpoint-id',
            'is_primary' => true,
            'last_seen_at' => now(),
        ]);
    }

    public function test_organization_integration_scope_key_unique_per_action1_group(): void
    {
        $tenant = Tenant::factory()->create();
        $organization = Organization::factory()->create(['tenant_id' => $tenant->id]);
        $groupA = (string) Str::uuid();
        $groupB = (string) Str::uuid();

        OrganizationIntegration::factory()->action1()->create([
            'tenant_id' => $tenant->id,
            'organization_id' => $organization->id,
            'config' => [
                'enabled' => true,
                'action1_organization_id' => 'org-1',
                'action1_group_id' => $groupA,
            ],
            'scope_key' => $groupA,
        ]);

        OrganizationIntegration::factory()->action1()->create([
            'tenant_id' => $tenant->id,
            'organization_id' => $organization->id,
            'config' => [
                'enabled' => true,
                'action1_organization_id' => 'org-1',
                'action1_group_id' => $groupB,
            ],
            'scope_key' => $groupB,
        ]);

        $this->assertSame(2, OrganizationIntegration::query()->withoutGlobalScopes()->count());

        $this->expectException(QueryException::class);

        OrganizationIntegration::factory()->action1()->create([
            'tenant_id' => $tenant->id,
            'organization_id' => $organization->id,
            'config' => [
                'enabled' => true,
                'action1_organization_id' => 'org-1',
                'action1_group_id' => $groupA,
            ],
            'scope_key' => $groupA,
        ]);
    }
}
