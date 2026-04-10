<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Document;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DocumentLinkTenantTest extends TestCase
{
    use RefreshDatabase;

    public function test_document_cannot_attach_to_asset_from_different_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $document = Document::factory()->create(['tenant_id' => $tenantA->id]);
        $asset = Asset::factory()->create(['tenant_id' => $tenantB->id]);

        $this->expectException(ValidationException::class);

        $asset->documents()->attach($document->id);
    }
}
