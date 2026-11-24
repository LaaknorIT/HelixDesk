<?php

namespace Tests\Feature;

use App\Enums\AssetExternalIdentifierType;
use App\Enums\DeviceClass;
use App\Enums\DeviceSourceRole;
use App\Filament\Agent\Resources\Organizations\OrganizationResource;
use App\Filament\Agent\Resources\Organizations\Pages\EditOrganizationIntegration;
use App\Models\Asset;
use App\Models\AssetSourceRecord;
use App\Models\AssetType;
use App\Models\CollectorRun;
use App\Models\Organization;
use App\Models\OrganizationIntegration;
use App\Models\Tenant;
use App\Models\User;
use App\Repositories\OrganizationIntegrationRepository;
use App\Services\ActiveDirectory\CollectorKeyService;
use App\Services\DeviceCoverageService;
use App\Support\ActiveDirectoryCollectorScript;
use App\Support\DeviceSourceCatalog;
use App\Support\DeviceSourceKey;
use App\Support\IntegrationHealth\IntegrationHealthInspector;
use App\Support\IntegrationType;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class ActiveDirectoryCollectorTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Organization $organization;

    private OrganizationIntegration $integration;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-22 12:00:00'));

        $this->tenant = Tenant::factory()->create();
        $this->organization = Organization::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->integration = app(OrganizationIntegrationRepository::class)->createActiveDirectoryFromForm($this->organization, [
            'ad_domain_fqdn' => 'Corp.Example.com',
            'ad_search_bases' => "OU=Computers,DC=corp,DC=example,DC=com\n\nOU=Servers,DC=corp,DC=example,DC=com",
            'ad_excluded_ous' => '',
            'ad_active_threshold_days' => 30,
            'ad_create_assets' => 'active_only',
            'ad_sync_interval_hours' => 4,
            'ad_enabled' => true,
        ]);
        $this->key = app(CollectorKeyService::class)->issue($this->integration);
    }

    public function test_form_data_becomes_config_and_short_thresholds_are_rejected(): void
    {
        $config = $this->integration->fresh()->config;

        $this->assertSame('corp.example.com', $config['domain_fqdn']);
        $this->assertSame(['OU=Computers,DC=corp,DC=example,DC=com', 'OU=Servers,DC=corp,DC=example,DC=com'], $config['search_bases']);
        $this->assertSame(['computers' => true], $config['scopes']);
        $this->assertSame('corp.example.com', $this->integration->scope_key);
        $this->assertSame(4, $this->integration->sync_interval_hours);

        $this->expectException(ValidationException::class);
        app(OrganizationIntegrationRepository::class)->createActiveDirectoryFromForm($this->organization, [
            'ad_domain_fqdn' => 'other.example.com',
            'ad_active_threshold_days' => 7,
            'ad_create_assets' => 'active_only',
        ]);
    }

    public function test_only_the_hash_of_the_collector_key_is_stored(): void
    {
        $credentials = $this->integration->fresh()->mergedCredentials();

        $this->assertStringStartsWith('hdc_', $this->key);
        $this->assertSame(hash('sha256', $this->key), $credentials['collector_key_hash']);
        $this->assertStringNotContainsString($this->key, json_encode($credentials));
    }

    public function test_issuing_a_key_is_logged_with_the_integration_id(): void
    {
        // activity_log.subject_id is a bigint and integrations have UUID keys, so the integration must not be the
        // subject: MySQL/MariaDB and PostgreSQL reject the insert (SQLite does not, which hid the bug).
        $activity = Activity::query()->where('event', 'collector_key_issued')->latest('id')->firstOrFail();

        $this->assertNull($activity->subject_id);
        $this->assertSame((string) $this->integration->id, $activity->getProperty('integration_id'));
        $this->assertStringNotContainsString($this->key, (string) json_encode($activity->properties));
    }

    public function test_replacing_the_key_shows_the_new_key_once_in_a_modal(): void
    {
        $this->actingAsTenantAdmin();

        $component = Livewire::test(EditOrganizationIntegration::class, [
            'record' => $this->organization->id,
            'integration' => (string) $this->integration->getKey(),
        ])
            ->callAction('collector_key')
            ->assertHasNoActionErrors()
            ->assertActionMounted('showCollectorKey');

        $newKey = (string) $component->get('mountedActions.0.arguments.key');
        $this->assertStringStartsWith('hdc_', $newKey);
        $this->assertNotSame($this->key, $newKey);
        $this->assertTrue(app(CollectorKeyService::class)->matches($this->integration->fresh(), $newKey));
        $this->assertFalse(app(CollectorKeyService::class)->matches($this->integration->fresh(), $this->key));
        // The modal's read-only fields hold the key and a ready-to-paste install command.
        $this->assertSame($newKey, $component->get('mountedActions.0.data.key'));
        $command = (string) $component->get('mountedActions.0.data.install_command');
        $this->assertStringContainsString("-HelixDeskUrl '".rtrim((string) config('app.url'), '/')."'", $command);
        $this->assertStringContainsString("-IntegrationId '{$this->integration->id}'", $command);
        $this->assertStringContainsString("-CollectorKey '{$newKey}'", $command);

        // The key from the modal works against the collector API, as the install command uses it.
        $this->getJson($this->url('config'), ['Authorization' => 'Bearer '.$newKey])->assertOk();
    }

    public function test_a_key_from_another_integration_is_refused_with_a_clear_message(): void
    {
        $otherOrganization = Organization::factory()->create(['tenant_id' => $this->tenant->id]);
        $other = app(OrganizationIntegrationRepository::class)->createActiveDirectoryFromForm($otherOrganization, [
            'ad_domain_fqdn' => 'other.example.com',
            'ad_search_bases' => '',
            'ad_excluded_ous' => '',
            'ad_active_threshold_days' => 30,
            'ad_create_assets' => 'active_only',
            'ad_sync_interval_hours' => 4,
            'ad_enabled' => true,
        ]);
        $otherKey = app(CollectorKeyService::class)->issue($other);

        // The script for this integration with the key for the other one: the mistake behind a 401 at install.
        $this->getJson($this->url('config'), ['Authorization' => 'Bearer '.$otherKey])
            ->assertUnauthorized()
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'does not match this integration'));

        $this->getJson($this->url('config'))
            ->assertUnauthorized()
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'No collector key was received'));

        $this->getJson('/api/v1/collectors/active-directory/00000000-0000-0000-0000-000000000000/config', $this->auth())
            ->assertUnauthorized()
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'No integration with this ID'));

        $this->getJson('/api/v1/collectors/active-directory/'.$other->id.'/config', ['Authorization' => 'Bearer '.$otherKey])->assertOk();
    }

    public function test_collector_endpoints_need_the_right_key_and_an_enabled_integration(): void
    {
        $url = $this->url('config');

        $this->getJson($url)->assertUnauthorized();
        $this->getJson($url, ['Authorization' => 'Bearer hdc_wrong'])->assertUnauthorized();

        $this->getJson($url, $this->auth())
            ->assertOk()
            ->assertJsonPath('data.domain_fqdn', 'corp.example.com')
            ->assertJsonPath('data.search_bases.1', 'OU=Servers,DC=corp,DC=example,DC=com')
            ->assertJsonPath('data.max_objects_per_batch', 200);

        $other = OrganizationIntegration::factory()->action1()->create([
            'tenant_id' => $this->tenant->id,
            'organization_id' => $this->organization->id,
        ]);
        $this->getJson('/api/v1/collectors/active-directory/'.$other->id.'/config', $this->auth())->assertUnauthorized();

        $this->integration->update(['enabled' => false]);
        $this->getJson($url, $this->auth())->assertForbidden();
    }

    public function test_a_complete_run_links_creates_and_marks_gone(): void
    {
        $intuneAsset = $this->asset('PC-0142');

        $runId = $this->postJson($this->url('runs'), ['collector_version' => '1.0.0', 'host' => 'SRV-MGMT'], $this->auth())
            ->assertCreated()
            ->json('data.run_id');

        $this->postJson($this->url("runs/{$runId}/objects"), ['sequence' => 0, 'objects' => [
            $this->object('11111111-0000-0000-0000-000000000001', 'PC-0142', lastLogon: now()->subDays(3)),
            $this->object('11111111-0000-0000-0000-000000000002', 'PC-0177', lastLogon: now()->subDays(1)),
            $this->object('11111111-0000-0000-0000-000000000003', 'PC-OLD', lastLogon: now()->subDays(200), pwdLastSet: now()->subDays(190)),
            $this->object('11111111-0000-0000-0000-000000000004', 'DC01', lastLogon: now()->subHour(), os: 'Windows Server 2022 Standard', dc: true),
        ]], $this->auth())->assertOk()->assertJsonPath('data.stored', 4);

        // A retried batch with the same sequence is ignored.
        $this->postJson($this->url("runs/{$runId}/objects"), ['sequence' => 0, 'objects' => [
            $this->object('11111111-0000-0000-0000-000000000001', 'PC-0142', lastLogon: now()->subDays(3)),
        ]], $this->auth())->assertOk()->assertJsonPath('data.stored', 0);

        $this->postJson($this->url("runs/{$runId}/complete"), ['object_count' => 4], $this->auth())
            ->assertOk()
            ->assertJsonPath('data.status', CollectorRun::STATUS_COMPLETED);

        $records = AssetSourceRecord::query()->withoutGlobalScopes()->get()->keyBy('display_name');
        $this->assertCount(4, $records);
        $this->assertSame($intuneAsset->id, $records['PC-0142']->asset_id, 'Linked to the existing asset by its unique computer name.');
        $this->assertNotNull($records['PC-0177']->asset_id, 'Active computer without an asset becomes an asset.');
        $this->assertNull($records['PC-OLD']->asset_id, 'Stale computer stays a source record only.');
        $this->assertSame(DeviceClass::Server, $records['DC01']->device_class);
        $this->assertSame('pc-0177', $records['PC-0177']->hostname);
        $this->assertSame('pc-0177.corp.example.com', $records['PC-0177']->fqdn);
        $this->assertSame('2026-11-21', $records['PC-0177']->last_activity_at->format('Y-m-d'));
        $this->assertSame(3, Asset::query()->withoutGlobalScopes()->count());
        $this->assertDatabaseHas('asset_external_identifiers', [
            'asset_id' => $intuneAsset->id,
            'provider' => IntegrationType::ACTIVE_DIRECTORY,
            'identifier_type' => AssetExternalIdentifierType::AdObjectGuid->value,
            'external_id' => '11111111-0000-0000-0000-000000000001',
        ]);
        $this->assertNotNull($this->integration->fresh()->last_sync_at);

        // Next run: PC-OLD was deleted from AD.
        $this->travel(4)->hours();
        $secondRun = $this->postJson($this->url('runs'), [], $this->auth())->json('data.run_id');
        $this->postJson($this->url("runs/{$secondRun}/objects"), ['sequence' => 0, 'objects' => [
            $this->object('11111111-0000-0000-0000-000000000001', 'PC-0142', lastLogon: now()->subDays(3)),
            $this->object('11111111-0000-0000-0000-000000000002', 'PC-0177', lastLogon: now()->subDays(1)),
            $this->object('11111111-0000-0000-0000-000000000004', 'DC01', lastLogon: now()->subHour(), os: 'Windows Server 2022 Standard', dc: true),
        ]], $this->auth())->assertOk();
        $this->postJson($this->url("runs/{$secondRun}/complete"), ['object_count' => 3], $this->auth())->assertOk();

        $this->assertNotNull($records['PC-OLD']->fresh()->gone_at);
        $this->assertNull($records['PC-0142']->fresh()->gone_at);
    }

    public function test_an_incomplete_run_marks_nothing_gone_and_is_reported(): void
    {
        $runId = $this->postJson($this->url('runs'), [], $this->auth())->json('data.run_id');
        $this->postJson($this->url("runs/{$runId}/objects"), ['sequence' => 0, 'objects' => [
            $this->object('22222222-0000-0000-0000-000000000001', 'PC-1', lastLogon: now()->subDay()),
        ]], $this->auth());
        $this->postJson($this->url("runs/{$runId}/complete"), ['object_count' => 1], $this->auth());

        $this->travel(1)->hour();
        $runId = $this->postJson($this->url('runs'), [], $this->auth())->json('data.run_id');
        $this->postJson($this->url("runs/{$runId}/complete"), ['object_count' => 250], $this->auth())
            ->assertOk()
            ->assertJsonPath('data.status', CollectorRun::STATUS_INCOMPLETE);

        $this->assertNull(AssetSourceRecord::query()->withoutGlobalScopes()->sole()->gone_at);
        $this->assertStringContainsString('received 0 of 250', $this->integration->fresh()->last_sync_error);

        $this->postJson($this->url("runs/{$runId}/objects"), ['sequence' => 1, 'objects' => []], $this->auth())->assertStatus(409);
    }

    public function test_active_domain_pc_missing_in_intune_is_a_coverage_finding(): void
    {
        OrganizationIntegration::factory()->create([
            'tenant_id' => $this->tenant->id,
            'organization_id' => $this->organization->id,
            'type' => IntegrationType::MICROSOFT_ENTRA,
            'enabled' => true,
            'config' => ['scopes' => ['computers' => true]],
        ]);

        $runId = $this->postJson($this->url('runs'), [], $this->auth())->json('data.run_id');
        $this->postJson($this->url("runs/{$runId}/objects"), ['sequence' => 0, 'objects' => [
            $this->object('33333333-0000-0000-0000-000000000001', 'PC-NOLICENSE', lastLogon: now()->subDays(2)),
        ]], $this->auth());

        $report = app(DeviceCoverageService::class)->build($this->tenant->id);

        $this->assertArrayHasKey(DeviceSourceKey::ACTIVE_DIRECTORY, $report->sources);
        $this->assertSame(['directory_to_device_management'], $report->rows[0]['findings']);
        $this->assertContains(DeviceSourceKey::ACTIVE_DIRECTORY, DeviceSourceCatalog::withRole(DeviceSourceRole::Directory));
    }

    public function test_health_reports_missing_key_and_a_silent_collector(): void
    {
        $withoutKey = app(OrganizationIntegrationRepository::class)->createActiveDirectoryFromForm($this->organization, [
            'ad_domain_fqdn' => 'branch.example.com',
            'ad_active_threshold_days' => 30,
            'ad_create_assets' => 'active_only',
            'ad_sync_interval_hours' => 4,
            'ad_enabled' => true,
        ]);
        $this->assertContains('collector_key_missing', collect(IntegrationHealthInspector::inspect($withoutKey)->issues)->pluck('code')->all());

        $this->postJson($this->url('heartbeat'), ['collector_version' => '1.0.0', 'host' => 'SRV-MGMT'], $this->auth())->assertOk();
        $this->assertNotContains('collector_silent', collect(IntegrationHealthInspector::inspect($this->integration->fresh())->issues)->pluck('code')->all());

        $this->travel(13)->hours();
        $issues = collect(IntegrationHealthInspector::inspect($this->integration->fresh())->issues);
        $this->assertContains('collector_silent', $issues->pluck('code')->all());
        $this->assertStringContainsString('SRV-MGMT', $issues->firstWhere('code', 'collector_silent')->detail);
    }

    public function test_script_has_the_url_and_integration_but_never_the_key(): void
    {
        $script = ActiveDirectoryCollectorScript::render($this->integration);

        $this->assertStringContainsString((string) $this->integration->id, $script);
        $this->assertStringContainsString(rtrim((string) config('app.url'), '/'), $script);
        $this->assertStringContainsString(ActiveDirectoryCollectorScript::VERSION, $script);
        $this->assertStringNotContainsString('__INTEGRATION_ID__', $script);
        $this->assertStringNotContainsString($this->key, $script);
        // A copied script per integration and a key file per integration, so two collectors on one machine do not
        // overwrite each other, and the scheduled task passes its integration explicitly.
        $this->assertStringContainsString('ad-collector-$IntegrationId.key', $script);
        $this->assertStringContainsString('HelixDesk-ADCollector-$IntegrationId.ps1', $script);
        $this->assertStringContainsString('-IntegrationId "{2}"', $script);
        $this->assertStringContainsString('MaximumRedirection = 0', $script);
    }

    public function test_edit_page_shows_the_ad_form_and_the_collector_actions(): void
    {
        $this->actingAsTenantAdmin();

        $this->get(OrganizationResource::getUrl('settings.integrations.edit', [
            'record' => $this->organization->id,
            'integration' => $this->integration->getKey(),
        ]))
            ->assertOk()
            ->assertSee('Domain name')
            ->assertSee('Replace collector key')
            ->assertSee('Download collector')
            ->assertDontSee('App registration');

    }

    private function actingAsTenantAdmin(): void
    {
        $admin = User::factory()->create(['tenant_id' => $this->tenant->id, 'type' => 'agent']);
        setPermissionsTeamId($this->tenant->id);
        $admin->assignRole('Tenant Admin');
        $this->actingAs($admin);
        Filament::setCurrentPanel('agent');
        Filament::setTenant($this->tenant, isQuiet: true);
    }

    private function url(string $path): string
    {
        return '/api/v1/collectors/active-directory/'.$this->integration->id.'/'.$path;
    }

    /**
     * @return array<string, string>
     */
    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.$this->key];
    }

    /**
     * @return array<string, mixed>
     */
    private function object(string $guid, string $name, Carbon $lastLogon, ?Carbon $pwdLastSet = null, string $os = 'Windows 11 Enterprise', bool $dc = false, bool $enabled = true): array
    {
        return [
            'object_guid' => $guid,
            'object_sid' => 'S-1-5-21-1000-2000-3000-'.substr($guid, -4),
            'name' => $name,
            'dns_host_name' => strtolower($name).'.corp.example.com',
            'operating_system' => $os,
            'operating_system_version' => '10.0 (22631)',
            'enabled' => $enabled,
            'is_domain_controller' => $dc,
            'last_logon_timestamp' => $lastLogon->toIso8601String(),
            'pwd_last_set' => ($pwdLastSet ?? $lastLogon)->toIso8601String(),
            'distinguished_name' => 'CN='.$name.',OU=Computers,DC=corp,DC=example,DC=com',
        ];
    }

    private function asset(string $name): Asset
    {
        $assetType = AssetType::query()->withoutGlobalScopes()->firstOrCreate(['tenant_id' => $this->tenant->id, 'name' => 'Computer']);

        return Asset::factory()->create([
            'tenant_id' => $this->tenant->id,
            'organization_id' => $this->organization->id,
            'asset_type_id' => $assetType->id,
            'name' => $name,
            'primary_hostname' => $name,
            'operating_system' => 'Windows 11 Enterprise',
        ]);
    }
}
