<?php

namespace Tests\Feature;

use App\Jobs\SyncNextAction1IntegrationJob;
use App\Jobs\SyncNextMicrosoftEntraIntegrationJob;
use App\Models\Organization;
use App\Models\OrganizationIntegration;
use App\Models\Tenant;
use App\Support\OrganizationIntegrationSettings;
use App\Support\OrganizationIntegrationSyncQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OrganizationIntegrationSyncQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_creatable_type_labels_include_action1(): void
    {
        $labels = OrganizationIntegrationSettings::creatableTypeLabels();

        $this->assertArrayHasKey(OrganizationIntegrationSettings::TYPE_ACTION1, $labels);
        $this->assertSame(__('Action1'), $labels[OrganizationIntegrationSettings::TYPE_ACTION1]);
    }

    public function test_dispatch_queues_entra_sync_job_when_ready(): void
    {
        Queue::fake();

        $tenant = Tenant::factory()->create();
        $organization = Organization::factory()->create(['tenant_id' => $tenant->id]);
        $integration = OrganizationIntegration::factory()->create([
            'tenant_id' => $tenant->id,
            'organization_id' => $organization->id,
            'type' => OrganizationIntegrationSettings::TYPE_MICROSOFT_ENTRA,
            'enabled' => true,
            'config' => [
                'enabled' => true,
                'azure_tenant_id' => 'tenant-id',
                'application_client_id' => 'client-id',
                'scopes' => ['users' => true],
            ],
            'credentials' => ['client_secret' => 'secret'],
        ]);

        $entry = OrganizationIntegrationSettings::findEntryForOrganization($organization, (string) $integration->id);
        $this->assertNotNull($entry);

        $result = OrganizationIntegrationSyncQueue::dispatch(
            $organization,
            (string) $integration->id,
            OrganizationIntegrationSettings::TYPE_MICROSOFT_ENTRA,
            $entry['config'],
        );

        $this->assertTrue($result['queued']);
        $this->assertNull($result['skip_reason']);

        Queue::assertPushed(SyncNextMicrosoftEntraIntegrationJob::class, function (SyncNextMicrosoftEntraIntegrationJob $job) use ($organization, $integration): bool {
            return $job->organizationId === $organization->id
                && $job->integrationId === (string) $integration->id;
        });
    }

    public function test_dispatch_queues_action1_sync_job_when_ready(): void
    {
        Queue::fake();

        $tenant = Tenant::factory()->create();
        $organization = Organization::factory()->create(['tenant_id' => $tenant->id]);
        $integration = OrganizationIntegration::factory()->create([
            'tenant_id' => $tenant->id,
            'organization_id' => $organization->id,
            'type' => OrganizationIntegrationSettings::TYPE_ACTION1,
            'enabled' => true,
            'config' => [
                'enabled' => true,
                'action1_organization_id' => 'org-123',
                'client_id' => 'client-id',
                'scopes' => ['endpoints' => true],
            ],
            'credentials' => ['client_secret' => 'secret'],
        ]);

        $entry = OrganizationIntegrationSettings::findEntryForOrganization($organization, (string) $integration->id);
        $this->assertNotNull($entry);

        $result = OrganizationIntegrationSyncQueue::dispatch(
            $organization,
            (string) $integration->id,
            OrganizationIntegrationSettings::TYPE_ACTION1,
            $entry['config'],
        );

        $this->assertTrue($result['queued']);

        Queue::assertPushed(SyncNextAction1IntegrationJob::class);
    }

    public function test_dispatch_does_not_queue_when_integration_disabled(): void
    {
        Queue::fake();

        $tenant = Tenant::factory()->create();
        $organization = Organization::factory()->create(['tenant_id' => $tenant->id]);

        $result = OrganizationIntegrationSyncQueue::dispatch(
            $organization,
            'integration-id',
            OrganizationIntegrationSettings::TYPE_ACTION1,
            ['enabled' => false],
        );

        $this->assertFalse($result['queued']);
        $this->assertSame('integration_disabled', $result['skip_reason']);

        Queue::assertNothingPushed();
    }

    public function test_google_workspace_is_not_an_asset_sync_type(): void
    {
        $this->assertNotContains(
            OrganizationIntegrationSettings::TYPE_GOOGLE_WORKSPACE,
            OrganizationIntegrationSettings::assetSyncTypes(),
        );
    }
}
