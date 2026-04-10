<?php

namespace Tests\Feature;

use App\Models\Change;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ChangeWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\TestCase;

class ChangeWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_without_approve_permission_cannot_approve_change(): void
    {
        $tenant = Tenant::factory()->create();

        $agent = User::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => 'agent',
        ]);

        setPermissionsTeamId($tenant->id);
        $agent->assignRole('Agent');
        setPermissionsTeamId(null);

        $change = Change::query()->create([
            'tenant_id' => $tenant->id,
            'title' => 'Network maintenance',
            'description' => 'Planned router upgrade.',
            'risk_id' => null,
            'status' => 'Review',
            'requested_by_user_id' => $agent->id,
            'approved_by_user_id' => null,
            'implementation_plan' => null,
            'rollback_plan' => null,
            'scheduled_start_at' => null,
            'scheduled_end_at' => null,
        ]);

        $this->expectException(AccessDeniedHttpException::class);

        app(ChangeWorkflowService::class)->transition($change, 'Approved', $agent);
    }

    public function test_admin_can_approve_change(): void
    {
        $tenant = Tenant::factory()->create();

        $requester = User::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => 'agent',
        ]);

        $admin = User::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => 'agent',
        ]);

        setPermissionsTeamId($tenant->id);
        $requester->assignRole('Agent');
        $admin->assignRole('Tenant Admin');
        setPermissionsTeamId(null);

        $change = Change::query()->create([
            'tenant_id' => $tenant->id,
            'title' => 'Network maintenance',
            'description' => 'Planned router upgrade.',
            'risk_id' => null,
            'status' => 'Review',
            'requested_by_user_id' => $requester->id,
            'approved_by_user_id' => null,
            'implementation_plan' => null,
            'rollback_plan' => null,
            'scheduled_start_at' => null,
            'scheduled_end_at' => null,
        ]);

        app(ChangeWorkflowService::class)->transition($change, 'Approved', $admin);

        $change->refresh();

        $this->assertSame('Approved', $change->status);
        $this->assertSame((int) $admin->id, (int) $change->approved_by_user_id);
    }

    public function test_agent_can_advance_change_after_approval(): void
    {
        $tenant = Tenant::factory()->create();

        $agent = User::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => 'agent',
        ]);

        $admin = User::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => 'agent',
        ]);

        setPermissionsTeamId($tenant->id);
        $agent->assignRole('Agent');
        $admin->assignRole('Tenant Admin');
        setPermissionsTeamId(null);

        $change = Change::query()->create([
            'tenant_id' => $tenant->id,
            'title' => 'Network maintenance',
            'description' => null,
            'risk_id' => null,
            'status' => 'Approved',
            'requested_by_user_id' => $agent->id,
            'approved_by_user_id' => $admin->id,
            'implementation_plan' => null,
            'rollback_plan' => null,
            'scheduled_start_at' => null,
            'scheduled_end_at' => null,
        ]);

        app(ChangeWorkflowService::class)->transition($change, 'Scheduled', $agent);

        $change->refresh();

        $this->assertSame('Scheduled', $change->status);
    }
}
