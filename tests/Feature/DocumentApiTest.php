<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_can_create_and_list_documents(): void
    {
        $tenant = Tenant::factory()->create();

        $agent = User::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => 'agent',
        ]);

        setPermissionsTeamId($tenant->id);
        $agent->assignRole('Agent');
        setPermissionsTeamId(null);

        $this->actingAs($agent);

        $this->postJson('/api/v1/documents', [
            'doc_type' => 'kb',
            'title' => 'Reset password',
            'content' => 'Open settings and choose security.',
        ])->assertCreated()
            ->assertJsonPath('data.title', 'Reset password')
            ->assertJsonPath('data.doc_type', 'kb');

        $this->getJson('/api/v1/documents')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_document_can_link_to_incident(): void
    {
        $tenant = Tenant::factory()->create();

        $agent = User::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => 'agent',
        ]);

        setPermissionsTeamId($tenant->id);
        $agent->assignRole('Agent');
        setPermissionsTeamId(null);

        $document = Document::factory()->create([
            'tenant_id' => $tenant->id,
            'doc_type' => 'kb',
            'status' => 'published',
        ]);

        $workItem = WorkItem::query()->create([
            'tenant_id' => $tenant->id,
            'title' => 'Cannot log in',
            'description' => 'User locked out.',
            'priority' => 'Medium',
            'status' => 'New',
            'opened_at' => now(),
        ]);

        $workItem->documents()->attach($document->getKey());

        $this->assertDatabaseHas('document_links', [
            'document_id' => $document->getKey(),
            'linkable_id' => $workItem->getKey(),
            'linkable_type' => WorkItem::class,
        ]);

        $this->actingAs($agent);

        $this->getJson('/api/v1/documents/'.$document->getKey())
            ->assertOk()
            ->assertJsonPath('data.work_items.0.id', $workItem->getKey());
    }
}
