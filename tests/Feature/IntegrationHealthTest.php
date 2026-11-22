<?php

namespace Tests\Feature;

use App\Enums\IntegrationHealthStatus;
use App\Filament\Agent\Pages\IntegrationGallery;
use App\Filament\Agent\Pages\Integrations;
use App\Filament\Agent\Resources\Organizations\OrganizationResource;
use App\Filament\Agent\Widgets\IntegrationSyncHealthWidget;
use App\Models\Organization;
use App\Models\OrganizationIntegration;
use App\Models\Tenant;
use App\Models\User;
use App\Services\MicrosoftEntra\MicrosoftEntraOrganizationSyncService;
use App\Support\EntraSyncTarget;
use App\Support\IntegrationCatalog;
use App\Support\IntegrationHealth\IntegrationConnectionChecker;
use App\Support\IntegrationHealth\IntegrationErrorExplainer;
use App\Support\IntegrationHealth\IntegrationHealthInspector;
use App\Support\IntegrationType;
use App\Support\OrganizationIntegrationSettings;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class IntegrationHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_recently_synced_integration_is_healthy(): void
    {
        $integration = $this->entraIntegration(['last_sync_at' => now()->subHour()]);

        $report = IntegrationHealthInspector::inspect($integration);

        $this->assertSame(IntegrationHealthStatus::Healthy, $report->status);
        $this->assertSame([], $report->issues);
    }

    public function test_disabled_integration_reports_no_issues(): void
    {
        $integration = $this->entraIntegration(['enabled' => false, 'last_sync_error' => 'HTTP 500']);

        $this->assertSame(IntegrationHealthStatus::Disabled, IntegrationHealthInspector::inspect($integration)->status);
    }

    public function test_sync_error_is_reported_with_an_actionable_hint(): void
    {
        $integration = $this->entraIntegration([
            'last_sync_at' => now()->subMonths(4),
            'last_sync_error' => 'Microsoft Entra token request failed: HTTP 401 {"error":"invalid_client","error_description":"AADSTS7000222: The provided client secret keys are expired."}',
            'last_sync_failed_at' => now()->subMinutes(10),
        ]);

        $report = IntegrationHealthInspector::inspect($integration);

        $this->assertSame(IntegrationHealthStatus::Error, $report->status);
        $this->assertSame('sync_failed', $report->errors()[0]->code);
        $this->assertStringContainsString('client secret has expired', $report->errors()[0]->detail);
        $this->assertStringNotContainsString('error_description', $report->errors()[0]->detail);
        $this->assertSame([], $report->warnings(), 'A failing integration should not also be flagged as overdue.');
    }

    public function test_client_secret_expiring_soon_is_a_warning_and_expired_is_an_error(): void
    {
        $expiring = $this->entraIntegration([
            'last_sync_at' => now()->subHour(),
            'config_extra' => ['client_secret_expires_at' => now()->addDays(10)->toDateString()],
        ]);
        $expired = $this->entraIntegration([
            'last_sync_at' => now()->subHour(),
            'config_extra' => ['client_secret_expires_at' => now()->subDay()->toDateString()],
        ]);
        $farAway = $this->entraIntegration([
            'last_sync_at' => now()->subHour(),
            'config_extra' => ['client_secret_expires_at' => now()->addYear()->toDateString()],
        ]);

        $expiringReport = IntegrationHealthInspector::inspect($expiring);
        $this->assertSame(IntegrationHealthStatus::Warning, $expiringReport->status);
        $this->assertSame('secret_expiring', $expiringReport->warnings()[0]->code);

        $this->assertSame(IntegrationHealthStatus::Error, IntegrationHealthInspector::inspect($expired)->status);
        $this->assertSame(IntegrationHealthStatus::Healthy, IntegrationHealthInspector::inspect($farAway)->status);
    }

    public function test_missing_graph_permission_for_an_enabled_capability_is_a_warning(): void
    {
        $integration = $this->entraIntegration([
            'last_sync_at' => now()->subHour(),
            'scopes' => ['users' => true, 'security_work_items' => true],
            'health_metadata' => ['granted_permissions' => ['Directory.Read.All']],
        ]);

        $report = IntegrationHealthInspector::inspect($integration);

        $this->assertSame(IntegrationHealthStatus::Warning, $report->status);
        $this->assertCount(1, $report->warnings());
        $this->assertSame('missing_permission:security_work_items', $report->warnings()[0]->code);
        $this->assertStringContainsString('SecurityIncident.Read.All', $report->warnings()[0]->detail);
        $this->assertStringContainsString('security incidents', $report->warnings()[0]->detail);
    }

    public function test_overdue_sync_is_a_warning(): void
    {
        $integration = $this->entraIntegration(['last_sync_at' => now()->subDays(10)]);

        $report = IntegrationHealthInspector::inspect($integration);

        $this->assertSame(IntegrationHealthStatus::Warning, $report->status);
        $this->assertSame('sync_stale', $report->warnings()[0]->code);
    }

    public function test_incomplete_setup_is_an_error_but_mail_only_entra_is_fine(): void
    {
        $noSecret = $this->entraIntegration(['credentials' => []]);
        $mailOnly = $this->entraIntegration(['scopes' => ['mail' => true]]);

        $this->assertSame('setup_incomplete', IntegrationHealthInspector::inspect($noSecret)->errors()[0]->code);
        $this->assertSame(IntegrationHealthStatus::Healthy, IntegrationHealthInspector::inspect($mailOnly)->status);
    }

    public function test_failed_sync_records_when_it_failed(): void
    {
        $integration = $this->entraIntegration();

        OrganizationIntegrationSettings::patchMicrosoftEntraIntegrationConfig(
            $integration->organization,
            (string) $integration->getKey(),
            ['last_entra_sync_error' => 'HTTP 500'],
        );

        $this->assertNotNull($integration->fresh()->last_sync_failed_at);
    }

    public function test_entra_sync_skips_capabilities_without_permission_and_keeps_syncing_the_rest(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();
            if (str_contains($url, 'login.microsoftonline.com')) {
                return Http::response(['access_token' => $this->fakeJwt(['User.Read.All']), 'expires_in' => 3600], 200);
            }
            if (str_contains($url, 'graph.microsoft.com/v1.0/users')) {
                return Http::response(['value' => []], 200);
            }

            return Http::response(['error' => 'unexpected '.$url], 500);
        });

        $integration = $this->entraIntegration(['scopes' => ['users' => true, 'security_work_items' => true]]);

        app(MicrosoftEntraOrganizationSyncService::class)->sync($this->targetFor($integration));

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'security/incidents'));

        $integration->refresh();
        $this->assertNull($integration->last_sync_error);
        $this->assertNotNull($integration->last_sync_at);
        $this->assertSame(['User.Read.All'], $integration->healthMetadata()['granted_permissions']);

        $report = IntegrationHealthInspector::inspect($integration);
        $this->assertSame(IntegrationHealthStatus::Warning, $report->status);
        $this->assertSame('missing_permission:security_work_items', $report->warnings()[0]->code);
    }

    public function test_entra_sync_fails_clearly_when_no_enabled_capability_has_permission(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => $this->fakeJwt([]), 'expires_in' => 3600], 200),
        ]);

        $integration = $this->entraIntegration(['scopes' => ['security_work_items' => true]]);

        $this->expectExceptionMessage('SecurityIncident.Read.All');

        app(MicrosoftEntraOrganizationSyncService::class)->sync($this->targetFor($integration));
    }

    public function test_entra_sync_detects_client_secret_expiry_when_allowed_to_read_the_app_registration(): void
    {
        $expiresAt = now()->addDays(5)->startOfSecond();

        Http::fake(function (Request $request) use ($expiresAt) {
            $url = $request->url();
            if (str_contains($url, 'login.microsoftonline.com')) {
                return Http::response(['access_token' => $this->fakeJwt(['User.Read.All', 'Application.Read.All']), 'expires_in' => 3600], 200);
            }
            if (str_contains($url, 'applications(')) {
                return Http::response(['passwordCredentials' => [
                    ['hint' => 'oth', 'endDateTime' => now()->addYear()->toIso8601String()],
                    ['hint' => 'sec', 'endDateTime' => $expiresAt->toIso8601String()],
                ]], 200);
            }

            return Http::response(['value' => []], 200);
        });

        $integration = $this->entraIntegration();
        app(MicrosoftEntraOrganizationSyncService::class)->sync($this->targetFor($integration));

        $integration->refresh();
        $this->assertSame($expiresAt->toIso8601String(), $integration->healthMetadata()['detected_client_secret_expires_at']);
        $this->assertSame('secret_expiring', IntegrationHealthInspector::inspect($integration)->warnings()[0]->code);
    }

    public function test_connection_check_records_result(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['error' => 'invalid_client', 'error_description' => 'AADSTS7000215: Invalid client secret provided.'], 401),
        ]);

        $integration = $this->entraIntegration(['last_sync_at' => now()->subHour()]);

        $result = IntegrationConnectionChecker::check($integration);

        $this->assertFalse($result['ok']);
        $report = IntegrationHealthInspector::inspect($integration->fresh());
        $this->assertSame('connection_check_failed', $report->errors()[0]->code);
        $this->assertStringContainsString('secret value, not the secret ID', $report->errors()[0]->detail);
    }

    public function test_error_explainer_recognises_laravel_request_exceptions(): void
    {
        $this->assertNotNull(IntegrationErrorExplainer::hint('Action1 GET audit failed: HTTP request returned status code 403: Forbidden'));
        $this->assertSame('Microsoft Graph GET users failed: HTTP 500', IntegrationErrorExplainer::summary('Microsoft Graph GET users failed: HTTP 500 {"error":{}}'));
    }

    public function test_catalog_search_filters_by_text_and_category(): void
    {
        $this->assertSame(
            [IntegrationType::ACTION1],
            array_column(IntegrationCatalog::search('patch'), 'type'),
        );
        $this->assertContains(
            IntegrationType::GOOGLE_WORKSPACE,
            array_column(IntegrationCatalog::search(null, IntegrationCatalog::CATEGORY_EMAIL), 'type'),
        );
        $this->assertNotContains(
            IntegrationType::ACTION1,
            array_column(IntegrationCatalog::search(null, IntegrationCatalog::CATEGORY_EMAIL), 'type'),
        );

        foreach (IntegrationType::registered() as $type) {
            $this->assertNotNull(IntegrationCatalog::find($type), "Integration type {$type} is missing from the catalog.");
        }
    }

    public function test_dashboard_widget_names_the_failing_integration_and_links_to_it(): void
    {
        $admin = $this->actAsTenantAdmin();
        $this->entraIntegration([
            'tenant' => $admin->tenant,
            'organization_name' => 'Acme AS',
            'last_sync_error' => 'Microsoft Graph GET users failed: HTTP 403 Forbidden',
        ]);

        Livewire::test(IntegrationSyncHealthWidget::class)
            ->assertSee('Microsoft Entra ID · Acme AS: Last sync failed')
            ->assertSee('filters%5Bstatus%5D%5Bvalue%5D=error', escape: false);
    }

    public function test_integrations_page_lists_issues_and_filters_by_status(): void
    {
        $admin = $this->actAsTenantAdmin();
        $failing = $this->entraIntegration([
            'tenant' => $admin->tenant,
            'organization_name' => 'Failing Org',
            'last_sync_error' => 'HTTP 403',
        ]);
        $this->entraIntegration([
            'tenant' => $admin->tenant,
            'organization_name' => 'Healthy Org',
            'last_sync_at' => now()->subHour(),
        ]);
        $otherTenant = $this->entraIntegration(['organization_name' => 'Other Tenant Org']);

        Livewire::test(Integrations::class)
            ->assertSee('Failing Org')
            ->assertSee('Healthy Org')
            ->assertDontSee('Other Tenant Org')
            ->assertSee('Last sync failed')
            ->filterTable('status', IntegrationHealthStatus::Error->value)
            ->assertSee('Failing Org')
            ->assertDontSee('Healthy Org');

        $this->assertNotSame($failing->tenant_id, $otherTenant->tenant_id);
    }

    public function test_gallery_lists_integrations_and_links_to_guided_setup_for_selected_organization(): void
    {
        $admin = $this->actAsTenantAdmin();
        $organization = Organization::factory()->create(['tenant_id' => $admin->tenant_id, 'name' => 'Picked Org']);

        Livewire::withQueryParams(['organization' => $organization->id])
            ->test(IntegrationGallery::class)
            ->assertSee('Microsoft Entra ID')
            ->assertSee('Action1')
            ->assertSee('Google Workspace')
            ->assertSee('settings/integrations/create?type=action1', escape: false)
            ->set('data.search', 'gmail')
            ->assertSee('Google Workspace')
            ->assertDontSee('Intune');
    }

    public function test_edit_page_shows_why_the_integration_fails(): void
    {
        $admin = $this->actAsTenantAdmin();
        $integration = $this->entraIntegration([
            'tenant' => $admin->tenant,
            'last_sync_error' => 'Microsoft Graph GET security/work_items failed: HTTP 400 {"error":{"message":"Resource not found for the segment \'work_items\'."}}',
        ]);

        $this->get(OrganizationResource::getUrl('settings.integrations.edit', [
            'record' => $integration->organization_id,
            'integration' => $integration->getKey(),
        ]))
            ->assertOk()
            ->assertSee('Last sync failed')
            ->assertSee('not recognised by Microsoft Graph');
    }

    public function test_edit_page_shows_the_setup_form_for_the_integrations_own_type(): void
    {
        $admin = $this->actAsTenantAdmin();
        $organization = Organization::factory()->create(['tenant_id' => $admin->tenant_id]);
        $integration = OrganizationIntegration::factory()->action1()->create([
            'tenant_id' => $admin->tenant_id,
            'organization_id' => $organization->id,
        ]);

        $this->get(OrganizationResource::getUrl('settings.integrations.edit', [
            'record' => $organization->id,
            'integration' => $integration->getKey(),
        ]))
            ->assertOk()
            ->assertSee('Action1 organization ID')
            ->assertDontSee('App registration');
    }

    private function actAsTenantAdmin(): User
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->create(['tenant_id' => $tenant->id, 'type' => 'agent']);

        setPermissionsTeamId($tenant->id);
        $admin->assignRole('Tenant Admin');

        $this->actingAs($admin);
        Filament::setCurrentPanel('agent');
        Filament::setTenant($tenant, isQuiet: true);

        return $admin->fresh();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function entraIntegration(array $overrides = []): OrganizationIntegration
    {
        $tenant = $overrides['tenant'] ?? Tenant::factory()->create();
        $organization = Organization::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => $overrides['organization_name'] ?? fake()->company(),
        ]);

        return OrganizationIntegration::query()->create([
            'tenant_id' => $tenant->id,
            'organization_id' => $organization->id,
            'type' => IntegrationType::MICROSOFT_ENTRA,
            'enabled' => $overrides['enabled'] ?? true,
            'config' => [
                'azure_tenant_id' => 'tenant-guid',
                'application_client_id' => 'client-guid',
                'scopes' => $overrides['scopes'] ?? ['users' => true],
                ...($overrides['config_extra'] ?? []),
            ],
            'credentials' => $overrides['credentials'] ?? ['client_secret' => 'secret-value'],
            'last_sync_at' => $overrides['last_sync_at'] ?? null,
            'last_sync_error' => $overrides['last_sync_error'] ?? null,
            'last_sync_failed_at' => $overrides['last_sync_failed_at'] ?? null,
            'health_metadata' => $overrides['health_metadata'] ?? null,
            'scope_key' => '',
        ]);
    }

    private function targetFor(OrganizationIntegration $integration): EntraSyncTarget
    {
        $entry = OrganizationIntegrationSettings::findEntryForOrganization($integration->organization, (string) $integration->getKey());

        return new EntraSyncTarget(
            tenantId: (int) $integration->tenant_id,
            organizationId: (int) $integration->organization_id,
            integrationId: (string) $integration->getKey(),
            config: $entry['config'],
        );
    }

    /**
     * @param  list<string>  $roles
     */
    private function fakeJwt(array $roles): string
    {
        $encode = fn (array $data): string => rtrim(strtr(base64_encode((string) json_encode($data)), '+/', '-_'), '=');

        return $encode(['alg' => 'none']).'.'.$encode(['roles' => $roles]).'.signature';
    }
}
