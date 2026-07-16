<?php

namespace App\Services;

use App\Models\Change;
use App\Models\User;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowTransition;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class ChangeWorkflowService
{
    public function transition(Change $change, string $toStatus, User $user): Change
    {
        if ($change->tenant_id === null) {
            throw new NotFoundHttpException('Change not found.');
        }

        if (! $user->canOperateInTenant((int) $change->tenant_id)) {
            throw new AccessDeniedHttpException('Forbidden.');
        }

        $workflowDefinition = WorkflowDefinition::query()
            ->where('tenant_id', $change->tenant_id)
            ->where('entity_type', 'change')
            ->where('is_default', true)
            ->firstOrFail();

        $transition = WorkflowTransition::query()
            ->where('tenant_id', $change->tenant_id)
            ->where('workflow_definition_id', $workflowDefinition->getKey())
            ->where('from_status', $change->status)
            ->where('to_status', $toStatus)
            ->first();

        if ($transition === null) {
            throw new UnprocessableEntityHttpException('Invalid change status transition.');
        }

        setPermissionsTeamId($change->tenant_id);

        try {
            if ($transition->required_permission !== null && ! $user->hasPermissionTo($transition->required_permission)) {
                throw new AccessDeniedHttpException('Forbidden.');
            }
        } finally {
            setPermissionsTeamId(null);
        }

        if ($toStatus === 'Approved') {
            $change->approved_by_user_id = $user->id;
        }

        $change->status = $toStatus;
        $change->save();

        return $change;
    }

    /**
     * @return array<string, string>
     */
    public function allowedTransitions(Change $change, User $user): array
    {
        if ($change->tenant_id === null || ! $user->canOperateInTenant((int) $change->tenant_id)) {
            return [];
        }

        $workflowDefinition = WorkflowDefinition::query()
            ->where('tenant_id', $change->tenant_id)
            ->where('entity_type', 'change')
            ->where('is_default', true)
            ->first();

        if ($workflowDefinition === null) {
            return [];
        }

        $transitions = WorkflowTransition::query()
            ->where('tenant_id', $change->tenant_id)
            ->where('workflow_definition_id', $workflowDefinition->getKey())
            ->where('from_status', $change->status)
            ->get();

        setPermissionsTeamId($change->tenant_id);

        try {
            $allowed = [];

            foreach ($transitions as $transition) {
                if ($transition->required_permission !== null && ! $user->hasPermissionTo($transition->required_permission)) {
                    continue;
                }

                $allowed[$transition->to_status] = $transition->to_status;
            }

            return $allowed;
        } finally {
            setPermissionsTeamId(null);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createChange(array $data, User $user): Change
    {
        $tenantId = $user->operatingTenantId();

        if ($tenantId === null || ! $user->canOperateInTenant($tenantId)) {
            throw new AccessDeniedHttpException('Forbidden.');
        }

        setPermissionsTeamId($tenantId);

        try {
            if (! $user->hasPermissionTo('change.create')) {
                throw new AccessDeniedHttpException('Forbidden.');
            }
        } finally {
            setPermissionsTeamId(null);
        }

        return Change::query()->create([
            'tenant_id' => $tenantId,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'risk_id' => $data['risk_id'] ?? null,
            'status' => 'Draft',
            'requested_by_user_id' => $user->id,
            'approved_by_user_id' => null,
            'implementation_plan' => $data['implementation_plan'] ?? null,
            'rollback_plan' => $data['rollback_plan'] ?? null,
            'scheduled_start_at' => $data['scheduled_start_at'] ?? null,
            'scheduled_end_at' => $data['scheduled_end_at'] ?? null,
        ]);
    }
}
