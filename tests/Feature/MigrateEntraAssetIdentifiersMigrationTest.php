<?php

namespace Tests\Feature;

use App\Enums\AssetExternalIdentifierType;
use App\Models\Asset;
use App\Models\AssetExternalIdentifier;
use App\Models\AssetType;
use App\Models\Organization;
use App\Models\OrganizationIntegration;
use App\Models\Tenant;
use App\Services\EntraAssetIdentifierMigrator;
use App\Support\AssetIdentifierHelper;
use App\Support\IntegrationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MigrateEntraAssetIdentifiersMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_moves_guid_asset_identifier_to_external_identifiers(): void
    {
        $tenant = Tenant::factory()->create();
        $organization = Organization::factory()->create(['tenant_id' => $tenant->id]);
        $integration = OrganizationIntegration::factory()->microsoftEntra()->create([
            'tenant_id' => $tenant->id,
            'organization_id' => $organization->id,
        ]);

        $azureDeviceId = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
        $assetType = AssetType::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Computer']);
        $asset = Asset::factory()->create([
            'tenant_id' => $tenant->id,
            'organization_id' => $organization->id,
            'asset_type_id' => $assetType->id,
            'asset_identifier' => $azureDeviceId,
            'name' => 'Legacy laptop',
        ]);

        app(EntraAssetIdentifierMigrator::class)->migrateAll();

        $this->assertDatabaseHas('asset_external_identifiers', [
            'asset_id' => $asset->id,
            'organization_integration_id' => $integration->id,
            'provider' => IntegrationType::MICROSOFT_ENTRA,
            'identifier_type' => AssetExternalIdentifierType::EntraAzureAdDeviceId->value,
            'external_id' => $azureDeviceId,
        ]);

        $asset->refresh();
        $this->assertSame(AssetIdentifierHelper::generatedAssetTag((int) $asset->id), $asset->asset_identifier);
    }

    public function test_migration_skips_non_guid_asset_identifiers(): void
    {
        $tenant = Tenant::factory()->create();
        $organization = Organization::factory()->create(['tenant_id' => $tenant->id]);
        OrganizationIntegration::factory()->microsoftEntra()->create([
            'tenant_id' => $tenant->id,
            'organization_id' => $organization->id,
        ]);

        $assetType = AssetType::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Computer']);
        Asset::factory()->create([
            'tenant_id' => $tenant->id,
            'organization_id' => $organization->id,
            'asset_type_id' => $assetType->id,
            'asset_identifier' => 'LAPTOP-001',
        ]);

        app(EntraAssetIdentifierMigrator::class)->migrateAll();

        $this->assertSame(0, AssetExternalIdentifier::query()->count());
    }
}
