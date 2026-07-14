<?php

namespace App\Jobs;

use App\Enums\IncidentSource;
use App\Models\Incident;
use App\Models\MonitoringEvent;
use App\Models\Tenant;
use App\Services\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;

class ProcessMonitoringEventJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $monitoringEventId) {}

    public function handle(TenantContext $tenantContext): void
    {
        /** @var MonitoringEvent|null $event */
        $event = MonitoringEvent::query()->find($this->monitoringEventId);

        if ($event === null || in_array($event->status, ['resolved', 'closed'], true)) {
            return;
        }

        $tenantId = (int) $event->tenant_id;

        $tenantContext->setTenantId($tenantId);
        setPermissionsTeamId($tenantId);

        try {
            if ($event->incidents()->exists()) {
                return;
            }

            $autoSeverities = array_map(
                'strtolower',
                config('helixdesk.monitoring.auto_incident_severities', [])
            );

            if (! in_array(strtolower($event->severity), $autoSeverities, true)) {
                return;
            }

            $tenant = Tenant::query()->find($tenantId);
            $queue = $tenant?->monitoringQueue() ?? $tenant?->generalQueue();

            if ($queue === null) {
                return;
            }

            $priority = match (strtolower($event->severity)) {
                'critical' => 'Critical',
                'high' => 'High',
                'low' => 'Low',
                default => 'Medium',
            };

            $title = mb_substr('[Monitoring] '.$event->message, 0, 250);

            $incident = Incident::query()->create([
                'tenant_id' => $tenantId,
                'organization_id' => null,
                'reported_by_user_id' => null,
                'assigned_agent_id' => null,
                'queue_id' => $queue->getKey(),
                'title' => $title,
                'description' => $event->message,
                'priority' => $priority,
                'category_id' => null,
                'status' => 'New',
                'source' => IncidentSource::Monitoring,
                'opened_at' => Carbon::now(),
                'resolved_at' => null,
                'closed_at' => null,
            ]);

            $event->incidents()->attach($incident->getKey());
        } finally {
            $tenantContext->setTenantId(null);
            setPermissionsTeamId(null);
        }
    }
}
