<?php

namespace App\Services\WorkItems;

use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkItemMessage;
use App\Models\WorkItemStatus;
use App\Services\SlaTimerService;
use App\Support\HelixdeskWorkItemAuthorization;
use App\Support\WorkItemQueueScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Linking, merging, watchers and CC addresses on tickets. See docs/agent-productivity.md.
 */
class WorkItemCollaborationService
{
    /**
     * How far {@see resolveMergeTarget()} follows a chain of merged tickets.
     */
    private const MAX_MERGE_DEPTH = 10;

    public function __construct(
        private readonly SlaTimerService $slaTimerService,
    ) {}

    public function canRead(User $user, WorkItem $workItem): bool
    {
        return $user->type !== 'end_user'
            && $workItem->tenant_id !== null
            && $user->canOperateInTenant((int) $workItem->tenant_id)
            && WorkItemQueueScope::canAccess($user, $workItem);
    }

    public function canUpdate(User $user, WorkItem $workItem): bool
    {
        return $this->canRead($user, $workItem)
            && HelixdeskWorkItemAuthorization::hasWorkItemPermission($user, 'update', (int) $workItem->queue_id);
    }

    public function link(WorkItem $workItem, WorkItem $other, User $user): void
    {
        $this->authorizeUpdate($user, $workItem);
        $this->authorizeRead($user, $other);
        $this->assertSameTenant($workItem, $other);

        if ((int) $workItem->getKey() === (int) $other->getKey()) {
            throw new UnprocessableEntityHttpException('A ticket cannot be linked to itself.');
        }

        if ($this->isLinked($workItem, $other)) {
            return;
        }

        $workItem->outgoingLinks()->attach($other->getKey(), ['created_by_user_id' => $user->getKey()]);
    }

    public function unlink(WorkItem $workItem, WorkItem $other, User $user): void
    {
        $this->authorizeUpdate($user, $workItem);

        $workItem->outgoingLinks()->detach($other->getKey());
        $workItem->incomingLinks()->detach($other->getKey());
    }

    public function isLinked(WorkItem $workItem, WorkItem $other): bool
    {
        return DB::table('work_item_links')
            ->where(function ($query) use ($workItem, $other): void {
                $query->where('work_item_id', $workItem->getKey())->where('linked_work_item_id', $other->getKey());
            })
            ->orWhere(function ($query) use ($workItem, $other): void {
                $query->where('work_item_id', $other->getKey())->where('linked_work_item_id', $workItem->getKey());
            })
            ->exists();
    }

    /**
     * Fold the source ticket into the target. Cannot be undone.
     */
    public function merge(WorkItem $source, WorkItem $target, User $user): WorkItem
    {
        $this->authorizeUpdate($user, $source);
        $this->authorizeUpdate($user, $target);
        $this->assertSameTenant($source, $target);

        if ((int) $source->getKey() === (int) $target->getKey()) {
            throw new UnprocessableEntityHttpException('A ticket cannot be merged into itself.');
        }

        if ($source->merged_into_work_item_id !== null) {
            throw new UnprocessableEntityHttpException('This ticket has already been merged.');
        }

        if ($target->merged_into_work_item_id !== null) {
            throw new UnprocessableEntityHttpException('The target ticket has been merged into another ticket.');
        }

        return DB::transaction(function () use ($source, $target, $user): WorkItem {
            WorkItemMessage::query()
                ->where('work_item_id', $source->getKey())
                ->update(['work_item_id' => $target->getKey()]);

            $this->mergeParticipants($source, $target);

            $source->merged_into_work_item_id = $target->getKey();
            $source->save();

            if (! $this->isLinked($source, $target)) {
                $source->outgoingLinks()->attach($target->getKey(), ['created_by_user_id' => $user->getKey()]);
            }

            $this->addNote($target, $user, "Ticket #{$source->getKey()} ({$source->title}) was merged into this ticket by {$user->name}.");
            $this->addNote($source, $user, "This ticket was merged into #{$target->getKey()} ({$target->title}) by {$user->name}.");

            $this->close($source);

            return $target->refresh();
        });
    }

    /**
     * The ticket that should receive activity for this one: itself, or the ticket it was merged into.
     */
    public function resolveMergeTarget(WorkItem $workItem): WorkItem
    {
        $current = $workItem;

        for ($depth = 0; $depth < self::MAX_MERGE_DEPTH && $current->merged_into_work_item_id !== null; $depth++) {
            $next = WorkItem::query()->find($current->merged_into_work_item_id);

            if ($next === null) {
                break;
            }

            $current = $next;
        }

        return $current;
    }

    public function watch(WorkItem $workItem, User $user, ?User $actor = null): void
    {
        $actor ??= $user;

        if ((int) $actor->getKey() === (int) $user->getKey()) {
            $this->authorizeRead($actor, $workItem);
        } else {
            $this->authorizeUpdate($actor, $workItem);
        }

        if (! $this->canRead($user, $workItem)) {
            throw new UnprocessableEntityHttpException("{$user->name} cannot read tickets in this queue.");
        }

        $workItem->watchers()->syncWithoutDetaching([$user->getKey()]);
    }

    public function unwatch(WorkItem $workItem, User $user, ?User $actor = null): void
    {
        $actor ??= $user;

        if ((int) $actor->getKey() !== (int) $user->getKey()) {
            $this->authorizeUpdate($actor, $workItem);
        }

        $workItem->watchers()->detach($user->getKey());
    }

    public function isWatching(WorkItem $workItem, User $user): bool
    {
        return $workItem->watchers()->whereKey($user->getKey())->exists();
    }

    /**
     * @param  list<string>  $addresses
     */
    public function setCcAddresses(WorkItem $workItem, array $addresses, User $user): void
    {
        $this->authorizeUpdate($user, $workItem);

        $workItem->cc_addresses = $this->normalizeAddresses($addresses, $workItem);
        $workItem->save();
    }

    /**
     * Trimmed, lower-cased, valid addresses without duplicates or the requester's own address.
     *
     * @param  array<int, mixed>  $addresses
     * @return list<string>
     */
    public function normalizeAddresses(array $addresses, WorkItem $workItem): array
    {
        $requesterEmail = Str::lower(trim((string) $workItem->reportedBy?->email));
        $normalized = [];

        foreach ($addresses as $address) {
            $address = Str::lower(trim((string) $address));

            if ($address === '' || $address === $requesterEmail || filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                continue;
            }

            $normalized[$address] = $address;
        }

        return array_values($normalized);
    }

    private function mergeParticipants(WorkItem $source, WorkItem $target): void
    {
        $target->watchers()->syncWithoutDetaching($source->watchers()->pluck('users.id')->all());

        $cc = [...$target->ccAddressList(), ...$source->ccAddressList()];
        $sourceRequesterEmail = $source->reportedBy?->email;

        if ($source->reported_by_user_id !== null
            && (int) $source->reported_by_user_id !== (int) $target->reported_by_user_id
            && filled($sourceRequesterEmail)) {
            $cc[] = $sourceRequesterEmail;
        }

        $target->cc_addresses = $this->normalizeAddresses($cc, $target);
        $target->save();
    }

    private function addNote(WorkItem $workItem, User $user, string $body): void
    {
        WorkItemMessage::query()->create([
            'tenant_id' => $workItem->tenant_id,
            'work_item_id' => $workItem->getKey(),
            'author_user_id' => $user->getKey(),
            'is_internal' => true,
            'body' => $body,
        ]);
    }

    /**
     * Close the merged ticket without going through the workflow: merging already needs Update on it.
     */
    private function close(WorkItem $workItem): void
    {
        $closedStatus = $this->closedStatusName($workItem) ?? 'Closed';
        $fromStatus = (string) $workItem->status;
        $now = now();

        $workItem->status = $closedStatus;
        $workItem->resolved_at ??= $now;
        $workItem->closed_at ??= $now;
        $workItem->save();

        if ($fromStatus !== $closedStatus) {
            $this->slaTimerService->syncPauseState($workItem, $fromStatus, $closedStatus);
        }
    }

    private function closedStatusName(WorkItem $workItem): ?string
    {
        $status = WorkItemStatus::query()
            ->where('tenant_id', $workItem->tenant_id)
            ->where(function ($query): void {
                $query->where('sets_closed_at', true)->orWhere('name', 'Closed');
            })
            ->orderByDesc('sets_closed_at')
            ->orderBy('sort_order')
            ->value('name');

        return is_string($status) ? $status : null;
    }

    private function authorizeRead(User $user, WorkItem $workItem): void
    {
        if (! $this->canRead($user, $workItem)) {
            throw new AccessDeniedHttpException('Forbidden.');
        }
    }

    private function authorizeUpdate(User $user, WorkItem $workItem): void
    {
        if (! $this->canUpdate($user, $workItem)) {
            throw new AccessDeniedHttpException('Forbidden.');
        }
    }

    private function assertSameTenant(WorkItem $a, WorkItem $b): void
    {
        if ((int) $a->tenant_id !== (int) $b->tenant_id) {
            throw new UnprocessableEntityHttpException('Tickets must belong to the same tenant.');
        }
    }
}
