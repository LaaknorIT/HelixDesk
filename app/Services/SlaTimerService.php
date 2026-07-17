<?php

namespace App\Services;

use App\Enums\SlaCalendarType;
use App\Enums\SlaTargetType;
use App\Models\SlaPolicy;
use App\Models\WorkItem;
use App\Models\WorkItemStatus;
use Illuminate\Support\Carbon;

class SlaTimerService
{
    public function startTimers(WorkItem $workItem): void
    {
        $policy = $workItem->slaPolicy ?? SlaPolicy::query()->find($workItem->sla_policy_id);
        if ($policy === null) {
            return;
        }

        $start = $workItem->opened_at ? Carbon::parse($workItem->opened_at) : now();

        if (in_array($policy->target_type, [SlaTargetType::FirstResponse->value, SlaTargetType::Both->value], true)
            && $policy->first_response_minutes !== null) {
            $workItem->first_response_due_at = $this->addMinutes(
                $start,
                (int) $policy->first_response_minutes,
                $policy->calendar_type
            );
        }

        if (in_array($policy->target_type, [SlaTargetType::Resolution->value, SlaTargetType::Both->value], true)
            && $policy->resolution_minutes !== null) {
            $workItem->resolution_due_at = $this->addMinutes(
                $start,
                (int) $policy->resolution_minutes,
                $policy->calendar_type
            );
        }

        if ($this->statusPausesSla($workItem)) {
            $workItem->sla_paused_at = now();
        }

        $workItem->save();
    }

    public function syncPauseState(WorkItem $workItem, string $fromStatus, string $toStatus): void
    {
        $fromPauses = $this->statusNamePausesSla((int) $workItem->tenant_id, $fromStatus);
        $toPauses = $this->statusNamePausesSla((int) $workItem->tenant_id, $toStatus);

        if (! $fromPauses && $toPauses) {
            $workItem->sla_paused_at = now();
            $workItem->save();

            return;
        }

        if ($fromPauses && ! $toPauses && $workItem->sla_paused_at !== null) {
            $pausedAt = Carbon::parse($workItem->sla_paused_at);
            $pausedSeconds = max(0, (int) now()->getTimestamp() - (int) $pausedAt->getTimestamp());
            $workItem->sla_paused_seconds = (int) $workItem->sla_paused_seconds + $pausedSeconds;
            $workItem->sla_paused_at = null;

            $policy = $workItem->slaPolicy ?? SlaPolicy::query()->find($workItem->sla_policy_id);
            if ($policy !== null) {
                if ($workItem->first_response_due_at !== null && $workItem->first_responded_at === null) {
                    $workItem->first_response_due_at = $this->addMinutes(
                        Carbon::parse($workItem->first_response_due_at),
                        (int) ceil($pausedSeconds / 60),
                        $policy->calendar_type
                    );
                }

                if ($workItem->resolution_due_at !== null && $workItem->resolved_at === null) {
                    $workItem->resolution_due_at = $this->addMinutes(
                        Carbon::parse($workItem->resolution_due_at),
                        (int) ceil($pausedSeconds / 60),
                        $policy->calendar_type
                    );
                }
            }

            $workItem->save();
        }
    }

    public function markFirstResponseIfNeeded(WorkItem $workItem): void
    {
        if ($workItem->first_responded_at !== null) {
            return;
        }

        $workItem->first_responded_at = now();
        $workItem->save();
    }

    public function evaluateBreaches(?Carbon $now = null): int
    {
        $now ??= now();
        $count = 0;

        WorkItem::query()
            ->whereNull('sla_paused_at')
            ->where(function ($query) use ($now): void {
                $query->where(function ($q) use ($now): void {
                    $q->whereNotNull('first_response_due_at')
                        ->whereNull('first_responded_at')
                        ->whereNull('response_breached_at')
                        ->where('first_response_due_at', '<', $now);
                })->orWhere(function ($q) use ($now): void {
                    $q->whereNotNull('resolution_due_at')
                        ->whereNull('resolved_at')
                        ->whereNull('resolution_breached_at')
                        ->where('resolution_due_at', '<', $now);
                });
            })
            ->orderBy('id')
            ->chunkById(100, function ($workItems) use ($now, &$count): void {
                foreach ($workItems as $workItem) {
                    $dirty = false;

                    if (
                        $workItem->first_response_due_at !== null
                        && $workItem->first_responded_at === null
                        && $workItem->response_breached_at === null
                        && $workItem->first_response_due_at->lt($now)
                    ) {
                        $workItem->response_breached_at = $now;
                        $dirty = true;
                    }

                    if (
                        $workItem->resolution_due_at !== null
                        && $workItem->resolved_at === null
                        && $workItem->resolution_breached_at === null
                        && $workItem->resolution_due_at->lt($now)
                    ) {
                        $workItem->resolution_breached_at = $now;
                        $dirty = true;
                    }

                    if ($dirty) {
                        $workItem->save();
                        $count++;
                    }
                }
            });

        return $count;
    }

    public function remainingSeconds(WorkItem $workItem, string $which = 'first_response'): ?int
    {
        $due = $which === 'resolution' ? $workItem->resolution_due_at : $workItem->first_response_due_at;
        if ($due === null) {
            return null;
        }

        if ($which === 'first_response' && $workItem->first_responded_at !== null) {
            return 0;
        }

        if ($which === 'resolution' && $workItem->resolved_at !== null) {
            return 0;
        }

        $reference = $workItem->sla_paused_at !== null
            ? Carbon::parse($workItem->sla_paused_at)
            : now();

        return (int) Carbon::parse($due)->getTimestamp() - (int) $reference->getTimestamp();
    }

    private function statusPausesSla(WorkItem $workItem): bool
    {
        return $this->statusNamePausesSla((int) $workItem->tenant_id, (string) $workItem->status);
    }

    private function statusNamePausesSla(int $tenantId, string $statusName): bool
    {
        return WorkItemStatus::query()
            ->where('tenant_id', $tenantId)
            ->where('name', $statusName)
            ->where('pauses_sla', true)
            ->exists();
    }

    private function addMinutes(Carbon $from, int $minutes, string $calendarType): Carbon
    {
        if ($calendarType === SlaCalendarType::Continuous->value) {
            return $from->copy()->addMinutes($minutes);
        }

        return $this->addWorkingMinutes($from, $minutes);
    }

    /**
     * Simple Mon–Fri 09:00–17:00 working calendar in the app timezone (v1).
     */
    private function addWorkingMinutes(Carbon $from, int $minutes): Carbon
    {
        $cursor = $from->copy();
        $remaining = $minutes;

        while ($remaining > 0) {
            if ($cursor->isWeekend()) {
                $cursor->next('Monday')->setTime(9, 0);

                continue;
            }

            $dayStart = $cursor->copy()->setTime(9, 0);
            $dayEnd = $cursor->copy()->setTime(17, 0);

            if ($cursor->lt($dayStart)) {
                $cursor = $dayStart;
            }

            if ($cursor->gte($dayEnd)) {
                $cursor->addDay()->setTime(9, 0);

                continue;
            }

            $available = (int) $cursor->diffInMinutes($dayEnd);
            if ($available >= $remaining) {
                return $cursor->copy()->addMinutes($remaining);
            }

            $remaining -= $available;
            $cursor->addDay()->setTime(9, 0);
        }

        return $cursor;
    }
}
