<?php

namespace Tests\Feature;

use App\Filament\Agent\Resources\Organizations\Pages\ListOrganizationSettings;
use App\Jobs\SyncNextAction1IntegrationJob;
use App\Jobs\SyncNextMicrosoftEntraIntegrationJob;
use App\Models\Organization;
use App\Models\OrganizationIntegration;
use App\Models\Tenant;
use App\Models\User;
use App\Support\IntegrationType;
use App\Support\OrganizationIntegrationSyncQueue;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class OrganizationIntegrationSyncRunTest extends TestCase
{
    use RefreshDatabase;

    public function test_run_action_queues_microsoft_entra_sync_job(): void
    {
        Queue::fake();

        [$tenant, $organization, $user] = $this->createTenantAdminContext();

        $integration = OrganizationIntegration::factory()->microsoftEntra()->create([
            'tenant_id' => $tenant->id,
            'organization_id' => $organization->id,
        ]);

        $this->actingAs($user);
        $this->configureAgentPanel($tenant);

        Livewire::test(ListOrganizationSettings::class, [
            'tenant' => $tenant,
            'record' => $organization->getKey(),
        ])
            ->callAction(TestAction::make('run')->table((string) $integration->getKey()))
            ->assertNotified();

        Queue::assertPushed(SyncNextMicrosoftEntraIntegrationJob::class, function (SyncNextMicrosoftEntraIntegrationJob $job) use ($organization, $integration): bool {
            return $job->organizationId === (int) $organization->getKey()
                && $job->integrationId === (string) $integration->getKey();
        });
    }

    public function test_run_action_queues_action1_sync_job(): void
    {
        Queue::fake();

        [$tenant, $organization, $user] = $this->createTenantAdminContext();

        $integration = OrganizationIntegration::factory()->action1()->create([
            'tenant_id' => $tenant->id,
            'organization_id' => $organization->id,
            'config' => [
                'enabled' => true,
                'client_id' => 'action1-client-id',
                'action1_organization_id' => 'action1-org-id',
                'scopes' => [
                    'endpoints' => true,
                    'software' => false,
                    'patches' => false,
                    'alerts' => false,
                ],
            ],
            'credentials' => [
                'client_secret' => 'action1-secret',
            ],
        ]);

        $this->actingAs($user);
        $this->configureAgentPanel($tenant);

        Livewire::test(ListOrganizationSettings::class, [
            'tenant' => $tenant,
            'record' => $organization->getKey(),
        ])
            ->callAction(TestAction::make('run')->table((string) $integration->getKey()))
            ->assertNotified();

        Queue::assertPushed(SyncNextAction1IntegrationJob::class, function (SyncNextAction1IntegrationJob $job) use ($organization, $integration): bool {
            return $job->organizationId === (int) $organization->getKey()
                && $job->integrationId === (string) $integration->getKey();
        });
    }

    public function test_run_action_shows_warning_when_integration_is_not_ready(): void
    {
        Queue::fake();

        [$tenant, $organization, $user] = $this->createTenantAdminContext();

        $integration = OrganizationIntegration::factory()->microsoftEntra()->create([
            'tenant_id' => $tenant->id,
            'organization_id' => $organization->id,
            'enabled' => false,
            'config' => [
                'enabled' => false,
                'azure_tenant_id' => 'tenant-guid',
                'application_client_id' => 'client-guid',
                'scopes' => [
                    'users' => true,
                    'departments' => false,
                    'computers' => false,
                    'security_work_items' => false,
                ],
            ],
            'credentials' => [
                'client_secret' => 'secret',
            ],
        ]);

        $this->actingAs($user);
        $this->configureAgentPanel($tenant);

        Livewire::test(ListOrganizationSettings::class, [
            'tenant' => $tenant,
            'record' => $organization->getKey(),
        ])
            ->callAction(TestAction::make('run')->table((string) $integration->getKey()))
            ->assertNotified();

        Queue::assertNothingPushed();
    }

    public function test_sync_queue_helper_reports_skip_reason_for_disabled_integration(): void
    {
        Queue::fake();

        $tenant = Tenant::factory()->create();
        $organization = Organization::factory()->create(['tenant_id' => $tenant->id]);

        $result = OrganizationIntegrationSyncQueue::dispatch(
            $organization,
            'integration-id',
            IntegrationType::MICROSOFT_ENTRA,
            [
                'enabled' => false,
                'azure_tenant_id' => 'tenant-guid',
                'application_client_id' => 'client-guid',
                'client_secret' => 'secret',
                'scopes' => ['users' => true],
            ],
        );

        $this->assertFalse($result['queued']);
        $this->assertSame('integration_disabled', $result['skip_reason']);
        Queue::assertNothingPushed();
    }

    /**
     * @return array{0: Tenant, 1: Organization, 2: User}
     */
    private function createTenantAdminContext(): array
    {
        $tenant = Tenant::factory()->create();
        $organization = Organization::factory()->create(['tenant_id' => $tenant->id]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => 'agent',
        ]);

        setPermissionsTeamId($tenant->id);
        $user->assignRole('Tenant Admin');
        setPermissionsTeamId(null);

        return [$tenant, $organization, $user];
    }

    private function configureAgentPanel(Tenant $tenant): void
    {
        Filament::setCurrentPanel('agent');
        Filament::setTenant($tenant, isQuiet: true);
        setPermissionsTeamId($tenant->id);
    }
}
