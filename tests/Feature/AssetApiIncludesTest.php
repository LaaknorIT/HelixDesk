<?php

namespace Tests\Feature;

use App\Enums\AssetExternalIdentifierType;
use App\Enums\AssetPatchComplianceStatus;
use App\Models\Asset;
use App\Models\AssetAttributeProvenance;
use App\Models\AssetExternalIdentifier;
use App\Models\AssetPatchItem;
use App\Models\AssetPatchStatus;
use App\Models\AssetSoftwareInstallation;
use App\Models\AssetType;
use App\Models\Organization;
use App\Models\OrganizationIntegration;
use App\Models\Tenant;
use App\Models\User;
use App\Support\IntegrationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssetApiIncludesTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_asset_supports_include_parameter(): void
    {
        $tenant = Tenant::factory()->create();
        $organization = Organization::factory()->create(['tenant_id' => $tenant->id]);
        $integration = OrganizationIntegration::factory()->create([
            'tenant_id' => $tenant->id,
            'organization_id' => $organization->id,
            'type' => IntegrationType::ACTION1,
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
            'external_id' => 'endpoint-1',
            'last_seen_at' => now(),
        ]);

        AssetSoftwareInstallation::query()->create([
            'tenant_id' => $tenant->id,
            'asset_id' => $asset->id,
            'organization_integration_id' => $integration->id,
            'provider' => IntegrationType::ACTION1,
            'external_id' => 'app-1',
            'name' => '7-Zip',
            'version' => '23.01',
            'source' => 'discovered',
            'last_seen_at' => now(),
        ]);

        $patchStatus = AssetPatchStatus::query()->create([
            'tenant_id' => $tenant->id,
            'asset_id' => $asset->id,
            'organization_integration_id' => $integration->id,
            'provider' => IntegrationType::ACTION1,
            'missing_critical_count' => 1,
            'missing_other_count' => 0,
            'last_assessed_at' => now(),
            'compliance_status' => AssetPatchComplianceStatus::CriticalMissing,
        ]);

        AssetPatchItem::query()->create([
            'tenant_id' => $tenant->id,
            'asset_patch_status_id' => $patchStatus->id,
            'asset_id' => $asset->id,
            'organization_integration_id' => $integration->id,
            'provider' => IntegrationType::ACTION1,
            'kb_id' => 'KB5036893',
            'title' => 'Security Update',
            'severity' => 'critical',
            'last_seen_at' => now(),
        ]);

        AssetAttributeProvenance::query()->create([
            'tenant_id' => $tenant->id,
            'asset_id' => $asset->id,
            'attribute' => 'name',
            'provider' => IntegrationType::ACTION1,
            'organization_integration_id' => $integration->id,
            'synced_at' => now(),
        ]);

        $agent = User::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => 'agent',
        ]);

        setPermissionsTeamId($tenant->id);
        $agent->assignRole('Agent');
        setPermissionsTeamId(null);

        $this->actingAs($agent);

        $response = $this->getJson('/api/v1/assets/'.$asset->id.'?include=external_identifiers,software_installations,patch_status,patch_items,provenance')
            ->assertOk();

        $response->assertJsonPath('data.external_identifiers.0.external_id', 'endpoint-1');
        $response->assertJsonPath('data.software_installations.0.name', '7-Zip');
        $response->assertJsonPath('data.patch_statuses.0.compliance_status', AssetPatchComplianceStatus::CriticalMissing->value);
        $response->assertJsonPath('data.patch_items.0.kb_id', 'KB5036893');
        $response->assertJsonPath('data.attribute_provenance.0.attribute', 'name');
    }

    public function test_index_asset_supports_include_parameter(): void
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
            'provider' => IntegrationType::ACTION1,
            'identifier_type' => AssetExternalIdentifierType::Action1EndpointId,
            'external_id' => 'endpoint-index',
            'last_seen_at' => now(),
        ]);

        $agent = User::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => 'agent',
        ]);

        setPermissionsTeamId($tenant->id);
        $agent->assignRole('Agent');
        setPermissionsTeamId(null);

        $this->actingAs($agent);

        $this->getJson('/api/v1/assets?include=external_identifiers')
            ->assertOk()
            ->assertJsonPath('data.0.external_identifiers.0.external_id', 'endpoint-index');
    }
}
