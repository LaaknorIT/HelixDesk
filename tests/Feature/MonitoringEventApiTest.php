<?php

namespace Tests\Feature;

use App\Jobs\ProcessMonitoringEventJob;
use App\Models\MonitoringEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class MonitoringEventApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_can_ingest_monitoring_event(): void
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

        $response = $this->postJson('/api/v1/monitoring-events', [
            'message' => 'Disk usage above 90% on db-01',
            'severity' => 'high',
            'external_reference' => 'zabbix:12345',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.message', 'Disk usage above 90% on db-01')
            ->assertJsonPath('data.occurrence_count', 1);

        $this->assertDatabaseCount('monitoring_events', 1);
    }

    public function test_events_path_alias_ingests_same_as_monitoring_events(): void
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

        $this->postJson('/api/v1/events', [
            'message' => 'Alias path ingest',
            'severity' => 'warning',
        ])->assertCreated()
            ->assertJsonPath('data.message', 'Alias path ingest');

        $this->assertDatabaseCount('monitoring_events', 1);
    }

    public function test_duplicate_within_window_increments_occurrence_count(): void
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

        $payload = [
            'message' => 'Same alert',
            'severity' => 'critical',
            'deduplication_key' => 'test-dedup-key',
        ];

        $this->postJson('/api/v1/monitoring-events', $payload)->assertCreated();
        $this->postJson('/api/v1/monitoring-events', $payload)->assertCreated();

        $this->assertDatabaseCount('monitoring_events', 1);

        $event = MonitoringEvent::query()->first();
        $this->assertSame(2, (int) $event->occurrence_count);
    }

    public function test_process_job_creates_incident_for_configured_severities(): void
    {
        Config::set('helixdesk.monitoring.auto_incident_severities', ['critical']);

        $tenant = Tenant::factory()->create();

        $agent = User::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => 'agent',
        ]);

        setPermissionsTeamId($tenant->id);
        $agent->assignRole('Agent');
        setPermissionsTeamId(null);

        $this->actingAs($agent);

        $response = $this->postJson('/api/v1/monitoring-events', [
            'message' => 'Host down',
            'severity' => 'critical',
        ]);

        $response->assertCreated();
        $eventId = $response->json('data.id');

        (new ProcessMonitoringEventJob((int) $eventId))->handle(app(TenantContext::class));

        $this->assertDatabaseCount('incidents', 1);
        $event = MonitoringEvent::query()->findOrFail($eventId);
        $this->assertTrue($event->incidents()->exists());
    }

    public function test_process_job_does_not_duplicate_incident(): void
    {
        Config::set('helixdesk.monitoring.auto_incident_severities', ['critical']);

        $tenant = Tenant::factory()->create();

        $agent = User::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => 'agent',
        ]);

        setPermissionsTeamId($tenant->id);
        $agent->assignRole('Agent');
        setPermissionsTeamId(null);

        $this->actingAs($agent);

        $response = $this->postJson('/api/v1/monitoring-events', [
            'message' => 'Host down',
            'severity' => 'critical',
        ]);

        $eventId = (int) $response->json('data.id');

        $job = new ProcessMonitoringEventJob($eventId);
        $tenantContext = app(TenantContext::class);
        $job->handle($tenantContext);
        $job->handle($tenantContext);

        $this->assertDatabaseCount('incidents', 1);
    }
}
