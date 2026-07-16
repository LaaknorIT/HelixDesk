<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantRole;
use App\Models\User;
use App\Models\WorkItem;
use App\Services\RolePermissionMatrixSync;
use App\Services\TenantAdminAssignmentService;
use App\Services\WorkItemWorkflowService;
use App\Support\HelixdeskWorkItemAuthorization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\TestCase;

class WorkItemQueuePermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_per_queue_admin_grants_update_even_when_update_toggle_is_off(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => 'agent',
            'is_admin' => false,
        ]);

        setPermissionsTeamId($tenant->id);
        $admin->assignRole('Tenant Admin');
        setPermissionsTeamId(null);

        $role = TenantRole::query()
            ->where('team_id', $tenant->id)
            ->where('name', 'Tenant Admin')
            ->firstOrFail();

        $general = $tenant->generalQueue();
        $this->assertNotNull($general);

        $global = [];
        foreach (config('helixdesk_permissions.modules') as $module => $cfg) {
            if ($module === 'work_item') {
                continue;
            }
            foreach ($cfg['actions'] as $action) {
                $global[$module][$action] = true;
            }
        }

        RolePermissionMatrixSync::sync($role, $global, [
            [
                'queue_id' => $general->id,
                'actions' => [
                    'read' => true,
                    'create' => false,
                    'update' => false,
                    'delete' => false,
                    'admin' => true,
                ],
            ],
        ]);

        setPermissionsTeamId($tenant->id);
        $admin = $admin->fresh();

        $this->assertTrue($admin->isTenantStaff());
        $this->assertTrue(HelixdeskWorkItemAuthorization::hasWorkItemPermission($admin, 'update', (int) $general->id));

        $workItem = WorkItem::query()->create([
            'tenant_id' => $tenant->id,
            'queue_id' => $general->id,
            'reported_by_user_id' => User::factory()->create([
                'tenant_id' => $tenant->id,
                'type' => 'end_user',
                'email' => 'req-'.uniqid().'@example.com',
            ])->id,
            'title' => 'Needs reply',
            'priority' => 'normal',
            'status' => 'New',
            'opened_at' => now(),
        ]);

        $message = app(WorkItemWorkflowService::class)->addInternalNote($workItem, 'Note from admin', $admin);
        $this->assertSame('Note from admin', $message->body);

        setPermissionsTeamId(null);
    }

    public function test_former_global_admin_with_tenant_admin_role_is_tenant_staff(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => 'agent',
            'is_admin' => false,
        ]);

        setPermissionsTeamId($tenant->id);
        $user->assignRole('Tenant Admin');
        setPermissionsTeamId(null);

        $this->assertTrue($user->fresh()->isTenantStaff());
        $this->assertTrue($user->fresh()->isTenantAdminFor($tenant->id));
    }

    public function test_missing_queue_update_and_admin_still_denies(): void
    {
        $tenant = Tenant::factory()->create();
        $agent = User::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => 'agent',
        ]);

        setPermissionsTeamId($tenant->id);
        $agent->assignRole('Agent');
        setPermissionsTeamId(null);

        $role = TenantRole::query()
            ->where('team_id', $tenant->id)
            ->where('name', 'Agent')
            ->firstOrFail();

        $general = $tenant->generalQueue();
        $this->assertNotNull($general);

        RolePermissionMatrixSync::sync($role, [
            'organization' => ['read' => true],
        ], [
            [
                'queue_id' => $general->id,
                'actions' => [
                    'read' => true,
                    'create' => false,
                    'update' => false,
                    'delete' => false,
                    'admin' => false,
                ],
            ],
        ]);

        setPermissionsTeamId($tenant->id);

        $workItem = WorkItem::query()->create([
            'tenant_id' => $tenant->id,
            'queue_id' => $general->id,
            'reported_by_user_id' => User::factory()->create([
                'tenant_id' => $tenant->id,
                'type' => 'end_user',
                'email' => 'req-'.uniqid().'@example.com',
            ])->id,
            'title' => 'Locked',
            'priority' => 'normal',
            'status' => 'New',
            'opened_at' => now(),
        ]);

        $this->expectException(AccessDeniedHttpException::class);
        app(WorkItemWorkflowService::class)->addInternalNote($workItem, 'Nope', $agent->fresh());
    }

    public function test_global_admin_with_tenant_admin_assignment_can_add_internal_note_with_null_tenant_id(): void
    {
        $tenant = Tenant::factory()->create();
        $globalAdmin = User::factory()->create([
            'tenant_id' => null,
            'type' => 'global_admin',
            'is_admin' => true,
        ]);

        app(TenantAdminAssignmentService::class)->assign($tenant, $globalAdmin);

        $general = $tenant->generalQueue();
        $this->assertNotNull($general);

        $workItem = WorkItem::query()->create([
            'tenant_id' => $tenant->id,
            'queue_id' => $general->id,
            'reported_by_user_id' => User::factory()->create([
                'tenant_id' => $tenant->id,
                'type' => 'end_user',
                'email' => 'req-'.uniqid().'@example.com',
            ])->id,
            'title' => 'Needs note from global admin',
            'priority' => 'normal',
            'status' => 'New',
            'opened_at' => now(),
        ]);

        setPermissionsTeamId($tenant->id);

        try {
            $globalAdmin = $globalAdmin->fresh();
            $this->assertNull($globalAdmin->tenant_id);
            $this->assertTrue($globalAdmin->canOperateInTenant($tenant));
            $this->assertTrue(HelixdeskWorkItemAuthorization::hasWorkItemPermission($globalAdmin, 'update', (int) $general->id));

            $message = app(WorkItemWorkflowService::class)->addInternalNote($workItem, 'Note from platform admin', $globalAdmin);

            $this->assertSame('Note from platform admin', $message->body);
            $this->assertSame($tenant->id, $message->tenant_id);
            $this->assertSame($globalAdmin->id, $message->author_user_id);
        } finally {
            setPermissionsTeamId(null);
        }
    }
}
