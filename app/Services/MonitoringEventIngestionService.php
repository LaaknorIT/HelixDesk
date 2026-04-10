<?php

namespace App\Services;

use App\Models\MonitoringEvent;
use App\Models\MonitoringEventSource;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class MonitoringEventIngestionService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function ingest(array $data, User $user): MonitoringEvent
    {
        if ($user->tenant_id === null) {
            abort(403);
        }

        $tenantId = (int) $user->tenant_id;

        $sourceSlug = $data['source_slug'] ?? 'api';

        /** @var MonitoringEventSource $source */
        $source = MonitoringEventSource::query()
            ->where('tenant_id', $tenantId)
            ->where('slug', $sourceSlug)
            ->firstOrFail();

        $message = (string) $data['message'];
        $severity = strtolower((string) $data['severity']);
        $externalReference = isset($data['external_reference']) ? (string) $data['external_reference'] : null;

        $deduplicationKey = $data['deduplication_key'] ?? null;
        if ($deduplicationKey === null || $deduplicationKey === '') {
            $deduplicationKey = hash('sha256', implode('|', [
                (string) $tenantId,
                (string) $source->getKey(),
                $externalReference ?? '',
                $severity,
                $message,
            ]));
        }

        $windowMinutes = max(1, (int) config('helixdesk.monitoring.deduplication_window_minutes', 15));
        $windowStart = Carbon::now()->subMinutes($windowMinutes);

        return DB::transaction(function () use ($tenantId, $source, $message, $severity, $externalReference, $deduplicationKey, $windowStart): MonitoringEvent {
            $existing = MonitoringEvent::query()
                ->where('tenant_id', $tenantId)
                ->where('deduplication_key', $deduplicationKey)
                ->whereIn('status', ['open', 'acknowledged'])
                ->where('last_seen_at', '>=', $windowStart)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                $existing->occurrence_count = (int) $existing->occurrence_count + 1;
                $existing->last_seen_at = Carbon::now();
                $existing->save();

                return $existing;
            }

            $now = Carbon::now();

            return MonitoringEvent::query()->create([
                'tenant_id' => $tenantId,
                'monitoring_event_source_id' => $source->getKey(),
                'external_reference' => $externalReference,
                'severity' => $severity,
                'message' => $message,
                'status' => 'open',
                'deduplication_key' => $deduplicationKey,
                'occurrence_count' => 1,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'resolved_at' => null,
            ]);
        });
    }
}
