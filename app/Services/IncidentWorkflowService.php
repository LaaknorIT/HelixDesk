<?php

namespace App\Services;

use App\Models\Incident;
use App\Models\IncidentMessage;
use App\Models\User;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowTransition;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class IncidentWorkflowService
{
    public function transition(Incident $incident, string $toStatus, User $user): Incident
    {
        if ($incident->tenant_id === null) {
            throw new NotFoundHttpException('Incident not found.');
        }

        if ($user->tenant_id === null) {
            throw new AccessDeniedHttpException('Forbidden.');
        }

        $workflowDefinition = WorkflowDefinition::query()
            ->where('entity_type', 'incident')
            ->where('is_default', true)
            ->firstOrFail();

        $transition = WorkflowTransition::query()
            ->where('workflow_definition_id', $workflowDefinition->getKey())
            ->where('from_status', $incident->status)
            ->where('to_status', $toStatus)
            ->first();

        if ($transition === null) {
            throw new UnprocessableEntityHttpException('Invalid incident status transition.');
        }

        if ($user->type === 'end_user') {
            if ($toStatus !== 'Closed') {
                throw new AccessDeniedHttpException('Forbidden.');
            }

            if ((int) $incident->reported_by_user_id !== (int) $user->id) {
                throw new AccessDeniedHttpException('Forbidden.');
            }
        } else {
            // Agents/tenant admins must have permission required by the workflow transition.
            if ($transition->required_permission !== null && ! $user->hasPermissionTo($transition->required_permission)) {
                throw new AccessDeniedHttpException('Forbidden.');
            }
        }

        // End users can reach a transition only when the workflow allows it.
        if ($transition->required_permission !== null && ! $user->hasPermissionTo($transition->required_permission)) {
            throw new AccessDeniedHttpException('Forbidden.');
        }

        $now = Carbon::now();

        $incident->status = $toStatus;

        if ($toStatus === 'Resolved' && $incident->resolved_at === null) {
            $incident->resolved_at = $now;
        }

        if ($toStatus === 'Closed' && $incident->closed_at === null) {
            $incident->closed_at = $now;
        }

        $incident->save();

        return $incident;
    }

    /**
     * @return array<string, string> map of `to_status` => label
     */
    public function allowedTransitions(Incident $incident, User $user): array
    {
        if ($user->tenant_id === null || $incident->tenant_id === null) {
            return [];
        }

        $workflowDefinition = WorkflowDefinition::query()
            ->where('entity_type', 'incident')
            ->where('is_default', true)
            ->first();

        if ($workflowDefinition === null) {
            return [];
        }

        $transitions = WorkflowTransition::query()
            ->where('workflow_definition_id', $workflowDefinition->getKey())
            ->where('from_status', $incident->status)
            ->get();

        $allowed = [];

        foreach ($transitions as $transition) {
            $toStatus = $transition->to_status;

            if ($user->type === 'end_user') {
                if ($toStatus !== 'Closed') {
                    continue;
                }

                if ((int) $incident->reported_by_user_id !== (int) $user->id) {
                    continue;
                }
            } else {
                if ($transition->required_permission !== null && ! $user->hasPermissionTo($transition->required_permission)) {
                    continue;
                }
            }

            // End-user transitions are still subject to workflow required permissions (if any).
            if ($transition->required_permission !== null && ! $user->hasPermissionTo($transition->required_permission)) {
                continue;
            }

            $allowed[$toStatus] = $toStatus;
        }

        return $allowed;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createIncident(array $data, User $user): Incident
    {
        if ($user->tenant_id === null) {
            throw new AccessDeniedHttpException('Forbidden.');
        }

        if ($user->type !== 'end_user' && ! $user->hasPermissionTo('manage_incidents')) {
            throw new AccessDeniedHttpException('Forbidden.');
        }

        $incident = Incident::query()->create([
            'tenant_id' => $user->tenant_id,
            'organization_id' => $data['organization_id'] ?? null,
            'department_id' => $data['department_id'] ?? null,
            'reported_by_user_id' => $user->id,
            'assigned_agent_id' => $data['assigned_agent_id'] ?? null,
            'queue_id' => $data['queue_id'] ?? null,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            // SLA-driven priority will be introduced later; default to "normal" for now.
            'priority' => $data['priority'] ?? 'normal',
            'category_id' => $data['category_id'] ?? null,
            'status' => 'New',
            'opened_at' => now(),
            'resolved_at' => null,
            'closed_at' => null,
        ]);

        return $incident;
    }

    public function addMessage(
        Incident $incident,
        string $body,
        User $user,
        ?int $parentMessageId = null,
        bool $isInternal = false,
    ): IncidentMessage {
        if ($user->tenant_id === null) {
            throw new AccessDeniedHttpException('Forbidden.');
        }

        if ($isInternal) {
            if ($user->type === 'end_user' || ! $user->hasPermissionTo('manage_incidents')) {
                throw new AccessDeniedHttpException('Forbidden.');
            }
        } else {
            if ($user->type === 'end_user') {
                if ((int) $incident->reported_by_user_id !== (int) $user->id) {
                    throw new AccessDeniedHttpException('Forbidden.');
                }
            } elseif (! $user->hasPermissionTo('manage_incidents')) {
                // Agents can only reply if they have manage_incidents permission.
                throw new AccessDeniedHttpException('Forbidden.');
            }
        }

        $parentMessage = null;

        if ($parentMessageId !== null) {
            $parentMessage = IncidentMessage::query()
                ->where('id', $parentMessageId)
                ->where('incident_id', $incident->getKey())
                ->first();

            if ($parentMessage === null) {
                throw new NotFoundHttpException('Parent message not found.');
            }

            // Only external replies can be threaded under other external replies.
            if ($parentMessage->is_internal) {
                throw new UnprocessableEntityHttpException('Cannot reply to an internal note.');
            }
        }

        /** @var IncidentMessage $message */
        $message = IncidentMessage::query()->create([
            'tenant_id' => $user->tenant_id,
            'incident_id' => $incident->getKey(),
            'author_user_id' => $user->id,
            'parent_message_id' => $parentMessage?->getKey(),
            'is_internal' => $isInternal,
            'body' => $body,
        ]);

        return $message;
    }

    public function addInternalNote(Incident $incident, string $body, User $user): IncidentMessage
    {
        return $this->addMessage($incident, $body, $user, null, true);
    }
}
