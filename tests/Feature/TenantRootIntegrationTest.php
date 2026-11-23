<?php

namespace Tests\Feature;

use App\Data\AssetProviderSnapshot;
use App\Data\AssetReconciliationResult;
use App\Enums\AssetExternalIdentifierType;
use App\Filament\Agent\Pages\Integrations;
use App\Filament\Agent\Resources\Organizations\OrganizationResource;
use App\Filament\Agent\Resources\Organizations\Pages\CreateOrganizationIntegration;
use App\Jobs\SyncNextAction1IntegrationJob;
use App\Models\Asset;
use App\Models\AssetType;
use App\Models\Organization;
use App\Models\OrganizationIntegration;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Action1\Action1OrganizationSyncService;
use App\Services\AssetReconciliationService;
use App\Services\OrganizationIntegrationMover;
use App\Support\IntegrationType;
use App\Support\OrganizationIntegrationSettings;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Integrations at the tenant root (no organization), such as an MSP-wide RMM that covers every customer.
 */
class TenantRootIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Organization $customer;

    private OrganizationIntegration $rootRmm;

    private OrganizationIntegration $entra;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->customer = Organization::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Customer A']);
        AssetType::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Computer']);

        $this->rootRmm = $this->action1Integration(null);
        $this->entra = OrganizationIntegration::factory()->create([
            'tenant_id' => $this->tenant->id,
            'organization_id' => $this->customer->id,
            'type' => IntegrationType::MICROSOFT_ENTRA,
        ]);
    }

    public function test_root_rmm_puts_new_devices_at_the_root_and_a_customer_integration_claims_them(): void
    {
        $first = $this->reconcile($this->rootRmm, $this->rmmSnapshot('endpoint-1', 'entra-device-1'));

        $this->assertTrue($first->created);
        $this->assertNull($first->asset->organization_id);

        $claimed = $this->reconcile($this->entra, new AssetProviderSnapshot(
            tenantId: $this->tenant->id,
            organizationId: $this->customer->id,
            organizationIntegrationId: (string) $this->entra->id,
            provider: IntegrationType::MICROSOFT_ENTRA,
            identifiers: [['type' => AssetExternalIdentifierType::EntraAzureAdDeviceId, 'value' => 'entra-device-1']],
            attributes: ['name' => 'PC-1'],
        ));

        $this->assertSame($first->asset->id, $claimed->asset->id);
        $this->assertSame($this->customer->id, (int) $first->asset->fresh()->organization_id);

        $again = $this->reconcile($this->rootRmm, $this->rmmSnapshot('endpoint-1', 'entra-device-1'));
        $this->assertSame($first->asset->id, $again->asset->id);
        $this->assertSame($this->customer->id, (int) $again->asset->fresh()->organization_id);
        $this->assertSame(1, Asset::query()->withoutGlobalScopes()->count());
    }

    public function test_root_rmm_does_not_see_devices_of_another_tenant(): void
    {
        $otherTenant = Tenant::factory()->create();
        AssetType::factory()->create(['tenant_id' => $otherTenant->id, 'name' => 'Computer']);
        $otherRoot = $this->action1Integration(null, $otherTenant);

        $mine = $this->reconcile($this->rootRmm, $this->rmmSnapshot('endpoint-1', 'entra-device-1'))->asset;
        $snapshot = $this->rmmSnapshot('endpoint-1', 'entra-device-1');
        $snapshot->tenantId = $otherTenant->id;
        $theirs = $this->reconcile($otherRoot, $snapshot)->asset;

        $this->assertNotSame($mine->id, $theirs->id);
        $this->assertSame($otherTenant->id, (int) $theirs->tenant_id);
    }

    public function test_moving_a_customer_rmm_to_the_root_keeps_its_devices_in_the_customer(): void
    {
        $customerRmm = $this->action1Integration($this->customer, scopeKey: 'group-a');
        $asset = $this->reconcile($customerRmm, $this->rmmSnapshot('endpoint-9', null))->asset;

        app(OrganizationIntegrationMover::class)->move($customerRmm, null);

        $this->assertNull($customerRmm->fresh()->organization_id);
        $this->assertSame($this->customer->id, (int) $asset->fresh()->organization_id);
    }

    public function test_moving_the_root_rmm_to_a_customer_moves_root_devices_down(): void
    {
        $asset = $this->reconcile($this->rootRmm, $this->rmmSnapshot('endpoint-1', null))->asset;

        $result = app(OrganizationIntegrationMover::class)->move($this->rootRmm, $this->customer);

        $this->assertSame(1, $result['moved_devices']);
        $this->assertSame($this->customer->id, (int) $asset->fresh()->organization_id);
    }

    public function test_only_one_identical_integration_can_sit_at_the_root(): void
    {
        $customerRmm = $this->action1Integration($this->customer);

        $this->expectException(ValidationException::class);

        app(OrganizationIntegrationMover::class)->move($customerRmm, null);
    }

    public function test_sync_job_runs_a_root_integration(): void
    {
        $baseUrl = config('helixdesk_integrations.action1.regions.us');
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'oauth2/token')) {
                return Http::response(['access_token' => 'fake-token', 'expires_in' => 3600], 200);
            }
            if (str_contains($request->url(), 'endpoints/managed/action1-org-1')) {
                return Http::response(['data' => [[
                    'id' => 'endpoint-root-1',
                    'name' => 'DESKTOP-ROOT',
                    'serial' => 'SN-ROOT-1',
                    'platform' => 'Windows',
                    'OS_version' => '11',
                ]]], 200);
            }

            return Http::response(['data' => []], 200);
        });
        $this->rootRmm->update(['api_base_url' => $baseUrl]);

        (new SyncNextAction1IntegrationJob(null, (string) $this->rootRmm->id))
            ->handle(app(Action1OrganizationSyncService::class));

        $asset = Asset::query()->withoutGlobalScopes()->where('name', 'DESKTOP-ROOT')->first();
        $this->assertNotNull($asset);
        $this->assertNull($asset->organization_id);
        $this->assertSame($this->tenant->id, (int) $asset->tenant_id);
        $this->assertNull($this->rootRmm->fresh()->last_sync_error);
        $this->assertNotNull($this->rootRmm->fresh()->last_sync_at);
    }

    public function test_scheduler_collects_root_integrations(): void
    {
        $targets = OrganizationIntegrationSettings::collectAction1SyncTargets();

        $root = collect($targets)->firstWhere('integrationId', (string) $this->rootRmm->id);
        $this->assertNotNull($root);
        $this->assertNull($root->organizationId);
    }

    public function test_root_settings_pages_open_and_offer_only_device_integrations(): void
    {
        $this->actAsTenantAdmin();

        $this->get(OrganizationResource::getUrl('settings', ['record' => Organization::TENANT_ROOT_ROUTE_KEY]))
            ->assertOk()
            ->assertSee('Root-level integrations')
            ->assertSee('Action1');

        $this->get(OrganizationResource::getUrl('settings.integrations.edit', [
            'record' => Organization::TENANT_ROOT_ROUTE_KEY,
            'integration' => $this->rootRmm->id,
        ]))->assertOk()->assertSee('Action1 organization ID');

        $this->get(OrganizationResource::getUrl('settings.integrations.create', [
            'record' => Organization::TENANT_ROOT_ROUTE_KEY,
            'type' => IntegrationType::ACTION1,
        ]))->assertOk();

        $this->get(OrganizationResource::getUrl('settings.integrations.create', [
            'record' => Organization::TENANT_ROOT_ROUTE_KEY,
            'type' => IntegrationType::MICROSOFT_ENTRA,
        ]))->assertNotFound();

        // A customer's integration is not reachable through the root URL.
        $this->get(OrganizationResource::getUrl('settings.integrations.edit', [
            'record' => Organization::TENANT_ROOT_ROUTE_KEY,
            'integration' => $this->entra->id,
        ]))->assertNotFound();
    }

    public function test_create_page_saves_an_integration_at_the_root(): void
    {
        $this->actAsTenantAdmin();
        $this->rootRmm->delete();

        Livewire::withQueryParams(['type' => IntegrationType::ACTION1])
            ->test(CreateOrganizationIntegration::class, ['record' => Organization::TENANT_ROOT_ROUTE_KEY])
            ->fillForm([
                'action1_enabled' => false,
                'action1_client_id' => 'client-id@action1.com',
                'action1_client_secret' => 'secret',
                'action1_organization_id' => 'action1-org-1',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $created = OrganizationIntegration::query()->withoutGlobalScopes()->where('type', IntegrationType::ACTION1)->sole();
        $this->assertNull($created->organization_id);
        $this->assertSame($this->tenant->id, (int) $created->tenant_id);

        Livewire::withQueryParams(['type' => IntegrationType::ACTION1])
            ->test(CreateOrganizationIntegration::class, ['record' => Organization::TENANT_ROOT_ROUTE_KEY])
            ->fillForm([
                'action1_client_id' => 'client-id@action1.com',
                'action1_client_secret' => 'secret',
                'action1_organization_id' => 'action1-org-1',
            ])
            ->call('save')
            ->assertHasFormErrors(['action1_organization_id']);

        $this->assertSame(1, OrganizationIntegration::query()->withoutGlobalScopes()->where('type', IntegrationType::ACTION1)->count());
    }

    public function test_integrations_page_lists_root_integrations_and_moves_one_to_the_root(): void
    {
        $this->actAsTenantAdmin();
        $this->rootRmm->delete();
        $customerRmm = $this->action1Integration($this->customer);

        Livewire::test(Integrations::class)
            ->callTableAction('move', (string) $customerRmm->id, ['organization_id' => Organization::TENANT_ROOT_ROUTE_KEY])
            ->assertHasNoTableActionErrors()
            ->assertNotified();

        $this->assertNull($customerRmm->fresh()->organization_id);

        Livewire::test(Integrations::class)->assertSee('All organizations (root level)');
    }

    public function test_identity_integrations_cannot_be_moved_to_the_root(): void
    {
        $this->actAsTenantAdmin();

        Livewire::test(Integrations::class)
            ->callTableAction('move', (string) $this->entra->id, ['organization_id' => Organization::TENANT_ROOT_ROUTE_KEY])
            ->assertHasTableActionErrors(['organization_id']);

        $this->assertSame($this->customer->id, (int) $this->entra->fresh()->organization_id);
    }

    private function actAsTenantAdmin(): void
    {
        $admin = User::factory()->create(['tenant_id' => $this->tenant->id, 'type' => 'agent']);
        setPermissionsTeamId($this->tenant->id);
        $admin->assignRole('Tenant Admin');
        $this->actingAs($admin);
        Filament::setCurrentPanel('agent');
        Filament::setTenant($this->tenant, isQuiet: true);
    }

    private function action1Integration(?Organization $organization, ?Tenant $tenant = null, string $scopeKey = ''): OrganizationIntegration
    {
        $tenant ??= $this->tenant;
        $config = [
            'action1_organization_id' => 'action1-org-1',
            'action1_group_id' => $scopeKey,
            'scopes' => ['endpoints' => true, 'software' => false, 'patches' => false, 'alerts' => false],
        ];

        return OrganizationIntegration::query()->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'organization_id' => $organization?->id,
            'type' => IntegrationType::ACTION1,
            'enabled' => true,
            'config' => $config,
            'credentials' => ['client_id' => 'client-id@action1.com', 'client_secret' => 'secret'],
            'api_base_url' => config('helixdesk_integrations.action1.default_api_base_url'),
            'scope_key' => OrganizationIntegration::resolveScopeKey(IntegrationType::ACTION1, $config),
        ]);
    }

    private function reconcile(OrganizationIntegration $integration, AssetProviderSnapshot $snapshot): AssetReconciliationResult
    {
        $snapshot->organizationId = $integration->organization_id !== null ? (int) $integration->organization_id : null;
        $snapshot->organizationIntegrationId = (string) $integration->id;

        return app(AssetReconciliationService::class)->reconcile($snapshot);
    }

    private function rmmSnapshot(string $endpointId, ?string $entraDeviceId): AssetProviderSnapshot
    {
        $identifiers = [['type' => AssetExternalIdentifierType::Action1EndpointId, 'value' => $endpointId]];
        if ($entraDeviceId !== null) {
            $identifiers[] = ['type' => AssetExternalIdentifierType::EntraAzureAdDeviceId, 'value' => $entraDeviceId];
        }

        return new AssetProviderSnapshot(
            tenantId: $this->tenant->id,
            organizationId: null,
            organizationIntegrationId: (string) $this->rootRmm->id,
            provider: IntegrationType::ACTION1,
            identifiers: $identifiers,
            attributes: ['name' => 'PC-'.$endpointId],
        );
    }
}
