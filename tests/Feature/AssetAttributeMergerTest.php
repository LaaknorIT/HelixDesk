<?php

namespace Tests\Feature;

use App\Data\AssetProviderSnapshot;
use App\Models\Asset;
use App\Models\AssetType;
use App\Models\Organization;
use App\Models\OrganizationIntegration;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AssetAttributeMerger;
use App\Support\IntegrationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssetAttributeMergerTest extends TestCase
{
    use RefreshDatabase;

    public function test_action1_wins_over_entra_for_name_when_both_sync(): void
    {
        $tenant = Tenant::factory()->create();
        $organization = Organization::factory()->create(['tenant_id' => $tenant->id]);
        $entraIntegration = OrganizationIntegration::factory()->create([
            'tenant_id' => $tenant->id,
            'organization_id' => $organization->id,
            'type' => IntegrationType::MICROSOFT_ENTRA,
        ]);
        $action1Integration = OrganizationIntegration::factory()->action1()->create([
            'tenant_id' => $tenant->id,
            'organization_id' => $organization->id,
        ]);
        $assetType = AssetType::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Computer']);
        $asset = Asset::factory()->create([
            'tenant_id' => $tenant->id,
            'organization_id' => $organization->id,
            'asset_type_id' => $assetType->id,
            'name' => 'Entra name',
        ]);

        $merger = app(AssetAttributeMerger::class);

        $merger->merge($asset, new AssetProviderSnapshot(
            tenantId: $tenant->id,
            organizationId: $organization->id,
            organizationIntegrationId: (string) $entraIntegration->id,
            provider: IntegrationType::MICROSOFT_ENTRA,
            identifiers: [],
            attributes: ['name' => 'Entra name'],
        ));

        $merger->merge($asset->fresh(), new AssetProviderSnapshot(
            tenantId: $tenant->id,
            organizationId: $organization->id,
            organizationIntegrationId: (string) $action1Integration->id,
            provider: IntegrationType::ACTION1,
            identifiers: [],
            attributes: ['name' => 'Action1 name'],
        ));

        $asset->refresh();
        $this->assertSame('Action1 name', $asset->name);
        $this->assertDatabaseHas('asset_attribute_provenance', [
            'asset_id' => $asset->id,
            'attribute' => 'name',
            'provider' => IntegrationType::ACTION1,
        ]);
    }

    public function test_entra_wins_over_action1_for_owner_user_id_after_action1_wrote_first(): void
    {
        $tenant = Tenant::factory()->create();
        $organization = Organization::factory()->create(['tenant_id' => $tenant->id]);
        $entraIntegration = OrganizationIntegration::factory()->create([
            'tenant_id' => $tenant->id,
            'organization_id' => $organization->id,
        ]);
        $action1Integration = OrganizationIntegration::factory()->action1()->create([
            'tenant_id' => $tenant->id,
            'organization_id' => $organization->id,
        ]);
        $action1Owner = User::factory()->create(['tenant_id' => $tenant->id]);
        $entraOwner = User::factory()->create(['tenant_id' => $tenant->id]);
        $assetType = AssetType::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Computer']);
        $asset = Asset::factory()->create([
            'tenant_id' => $tenant->id,
            'organization_id' => $organization->id,
            'asset_type_id' => $assetType->id,
            'owner_user_id' => null,
        ]);

        $merger = app(AssetAttributeMerger::class);

        $merger->merge($asset, new AssetProviderSnapshot(
            tenantId: $tenant->id,
            organizationId: $organization->id,
            organizationIntegrationId: (string) $action1Integration->id,
            provider: IntegrationType::ACTION1,
            identifiers: [],
            attributes: ['owner_user_id' => $action1Owner->id],
        ));

        $merger->merge($asset->fresh(), new AssetProviderSnapshot(
            tenantId: $tenant->id,
            organizationId: $organization->id,
            organizationIntegrationId: (string) $entraIntegration->id,
            provider: IntegrationType::MICROSOFT_ENTRA,
            identifiers: [],
            attributes: ['owner_user_id' => $entraOwner->id],
        ));

        $asset->refresh();
        $this->assertSame((int) $entraOwner->id, (int) $asset->owner_user_id);
    }

    public function test_provider_priority_respects_source_of_truth_matrix(): void
    {
        $merger = app(AssetAttributeMerger::class);

        $this->assertSame(0, $merger->providerPriority('name', IntegrationType::ACTION1));
        $this->assertSame(1, $merger->providerPriority('name', IntegrationType::MICROSOFT_ENTRA));
        $this->assertSame(0, $merger->providerPriority('owner_user_id', IntegrationType::MICROSOFT_ENTRA));
        $this->assertSame(1, $merger->providerPriority('owner_user_id', IntegrationType::ACTION1));
    }
}
