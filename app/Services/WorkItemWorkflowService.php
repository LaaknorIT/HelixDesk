<?php

namespace App\Services;

use App\Enums\WorkItemSource;
use App\Enums\WorkItemType;
use App\Jobs\SendWorkItemReplyMailJob;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowTransition;
use App\Models\WorkItem;
use App\Models\WorkItemMessage;
use App\Support\HelixdeskWorkItemAuthorization;
use App\Support\WorkItemQueueScope;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class WorkItemWorkflowService
{
    public function transition(WorkItem $workItem, string $toStatus, User $user): WorkItem
    {
        if ($workItem->tenant_id === null) {
            throw new NotFoundHttpException('Work item not found.');
        }

        if ($user->tenant_id === null) {
            throw new AccessDeniedHttpException('Forbidden.');
        }

        $workflowDefinition = WorkflowDefinition::query()
            ->where('entity_type', 'work_item')
            ->where('is_default', true)
            ->firstOrFail();

        $transition = WorkflowTransition::query()
            ->where('workflow_definition_id', $workflowDefinition->getKey())
            ->where('from_status', $workItem->status)
            ->where('to_status', $toStatus)
            ->first();

        if ($transition === null) {
            throw new UnprocessableEntityHttpException('Invalid work item status transition.');
        }

        if ($user->type === 'end_user') {
            if ($toStatus !== 'Closed') {
                throw new AccessDeniedHttpException('Forbidden.');
            }

            if ((int) $workItem->reported_by_user_id !== (int) $user->id) {
                throw new AccessDeniedHttpException('Forbidden.');
            }
        } else {
            if (! WorkItemQueueScope::canAccess($user, $workItem)) {
                throw new AccessDeniedHttpException('Forbidden.');
            }

            // Agents/tenant admins must have permission required by the workflow transition.
            if (! HelixdeskWorkItemAuthorization::satisfiesWorkflowPermission($user, $transition->required_permission, $workItem)) {
                throw new AccessDeniedHttpException('Forbidden.');
            }
        }

        // End users can reach a transition only when the workflow allows it.
        if (! HelixdeskWorkItemAuthorization::satisfiesWorkflowPermission($user, $transition->required_permission, $workItem)) {
            throw new AccessDeniedHttpException('Forbidden.');
        }

        $now = Carbon::now();

        $workItem->status = $toStatus;

        if ($toStatus === 'Resolved' && $workItem->resolved_at === null) {
            $workItem->resolved_at = $now;
        }

        if ($toStatus === 'Closed' && $workItem->closed_at === null) {
            $workItem->closed_at = $now;
        }

        $workItem->save();

        return $workItem;
    }

    /**
     * @return array<string, string> map of `to_status` => label
     */
    public function allowedTransitions(WorkItem $workItem, User $user): array
    {
        if ($user->tenant_id === null || $workItem->tenant_id === null) {
            return [];
        }

        $workflowDefinition = WorkflowDefinition::query()
            ->where('entity_type', 'work_item')
            ->where('is_default', true)
            ->first();

        if ($workflowDefinition === null) {
            return [];
        }

        $transitions = WorkflowTransition::query()
            ->where('workflow_definition_id', $workflowDefinition->getKey())
            ->where('from_status', $workItem->status)
            ->get();

        $allowed = [];

        foreach ($transitions as $transition) {
            $toStatus = $transition->to_status;

            if ($user->type === 'end_user') {
                if ($toStatus !== 'Closed') {
                    continue;
                }

                if ((int) $workItem->reported_by_user_id !== (int) $user->id) {
                    continue;
                }
            } else {
                if (! HelixdeskWorkItemAuthorization::satisfiesWorkflowPermission($user, $transition->required_permission, $workItem)) {
                    continue;
                }
            }

            // End-user transitions are still subject to workflow required permissions (if any).
            if (! HelixdeskWorkItemAuthorization::satisfiesWorkflowPermission($user, $transition->required_permission, $workItem)) {
                continue;
            }

            $allowed[$toStatus] = $toStatus;
        }

        return $allowed;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createWorkItem(array $data, User $user): WorkItem
    {
        if ($user->tenant_id === null) {
            throw new AccessDeniedHttpException('Forbidden.');
        }

        $queueId = $data['queue_id'] ?? null;

        if ($queueId === null) {
            $tenant = Tenant::query()->find($user->tenant_id);
            $queueId = $tenant?->generalQueue()?->getKey();
        }

        if ($user->type !== 'end_user') {
            if ($queueId === null || ! HelixdeskWorkItemAuthorization::hasWorkItemPermission($user, 'create', (int) $queueId)) {
                throw new AccessDeniedHttpException('Forbidden.');
            }
        }

        $workItem = WorkItem::query()->create([
            'tenant_id' => $user->tenant_id,
            'organization_id' => $data['organization_id'] ?? $user->organization_id,
            'reported_by_user_id' => $user->id,
            'assigned_agent_id' => $data['assigned_agent_id'] ?? null,
            'queue_id' => $queueId,
            'type' => $data['type'] ?? WorkItemType::Incident,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            // SLA-driven priority will be introduced later; default to "normal" for now.
            'priority' => $data['priority'] ?? 'normal',
            'category_id' => $data['category_id'] ?? null,
            'status' => 'New',
            'source' => WorkItemSource::Portal,
            'opened_at' => now(),
            'resolved_at' => null,
            'closed_at' => null,
        ]);

        return $workItem;
    }

    public function addMessage(
        WorkItem $workItem,
        string $body,
        User $user,
        ?int $parentMessageId = null,
        bool $isInternal = false,
    ): WorkItemMessage {
        if ($user->tenant_id === null) {
            throw new AccessDeniedHttpException('Forbidden.');
        }

        if ($isInternal) {
            if ($user->type === 'end_user' || $workItem->queue_id === null
                || ! HelixdeskWorkItemAuthorization::hasWorkItemPermission($user, 'update', (int) $workItem->queue_id)) {
                throw new AccessDeniedHttpException('Forbidden.');
            }

            if (! WorkItemQueueScope::canAccess($user, $workItem)) {
                throw new AccessDeniedHttpException('Forbidden.');
            }
        } else {
            if ($user->type === 'end_user') {
                if ((int) $workItem->reported_by_user_id !== (int) $user->id) {
                    throw new AccessDeniedHttpException('Forbidden.');
                }
            } elseif ($workItem->queue_id === null
                || ! HelixdeskWorkItemAuthorization::hasWorkItemPermission($user, 'update', (int) $workItem->queue_id)) {
                throw new AccessDeniedHttpException('Forbidden.');
            } elseif (! WorkItemQueueScope::canAccess($user, $workItem)) {
                throw new AccessDeniedHttpException('Forbidden.');
            }
        }

        $parentMessage = null;

        if ($parentMessageId !== null) {
            $parentMessage = WorkItemMessage::query()
                ->where('id', $parentMessageId)
                ->where('work_item_id', $workItem->getKey())
                ->first();

            if ($parentMessage === null) {
                throw new NotFoundHttpException('Parent message not found.');
            }

            // Only external replies can be threaded under other external replies.
            if ($parentMessage->is_internal) {
                throw new UnprocessableEntityHttpException('Cannot reply to an internal note.');
            }
        }

        /** @var WorkItemMessage $message */
        $message = WorkItemMessage::query()->create([
            'tenant_id' => $user->tenant_id,
            'work_item_id' => $workItem->getKey(),
            'author_user_id' => $user->id,
            'parent_message_id' => $parentMessage?->getKey(),
            'is_internal' => $isInternal,
            'body' => $body,
        ]);

        if (! $isInternal && $user->type !== 'end_user') {
            // Run after the HTTP/Livewire response so a sync-queue Graph/SMTP failure
            // cannot abort saving the conversation message or show a generic page error.
            SendWorkItemReplyMailJob::dispatch($message->getKey())->afterResponse();
        }

        return $message;
    }

    public function addInternalNote(WorkItem $workItem, string $body, User $user): WorkItemMessage
    {
        return $this->addMessage($workItem, $body, $user, null, true);
    }
}
