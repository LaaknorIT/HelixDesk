<?php

namespace Tests\Feature;

use App\Enums\AutomationActionType;
use App\Filament\Agent\Resources\AutomationRules\Pages\EditAutomationRule;
use App\Models\AutomationRule;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AutomationRuleFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_set_status_action_shows_status_selector_on_edit(): void
    {
        $tenant = Tenant::factory()->create();

        $admin = User::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => 'agent',
        ]);

        setPermissionsTeamId($tenant->id);
        $admin->assignRole('Tenant Admin');
        setPermissionsTeamId(null);

        $rule = AutomationRule::query()
            ->where('tenant_id', $tenant->id)
            ->where('name', 'Customer reply → Replied')
            ->with('actions')
            ->firstOrFail();

        $this->assertSame(AutomationActionType::SetStatus->value, $rule->actions->first()->action_type);
        $this->assertSame('Replied', $rule->actions->first()->action_config_json['status'] ?? null);

        $this->actingAs($admin);

        Filament::setCurrentPanel('agent');
        Filament::setTenant($tenant, isQuiet: true);

        Livewire::test(EditAutomationRule::class, [
            'tenant' => $tenant,
            'record' => $rule->getKey(),
        ])
            ->assertOk()
            ->assertSee('Set status to')
            ->assertSee('Replied');
    }

    public function test_changing_action_type_to_set_status_reveals_status_field(): void
    {
        $tenant = Tenant::factory()->create();

        $admin = User::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => 'agent',
        ]);

        setPermissionsTeamId($tenant->id);
        $admin->assignRole('Tenant Admin');
        setPermissionsTeamId(null);

        $rule = AutomationRule::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Custom rule',
            'is_enabled' => true,
            'is_system' => false,
            'trigger' => 'customer_replied',
            'conditions_json' => ['statuses' => ['Working']],
            'sort_order' => 100,
        ]);

        $rule->actions()->create([
            'tenant_id' => $tenant->id,
            'sort_order' => 0,
            'action_type' => AutomationActionType::CreateWorkItem->value,
            'action_config_json' => [
                'move_reply_message' => true,
                'link_to_original' => true,
            ],
        ]);

        $this->actingAs($admin);

        Filament::setCurrentPanel('agent');
        Filament::setTenant($tenant, isQuiet: true);

        Livewire::test(EditAutomationRule::class, [
            'tenant' => $tenant,
            'record' => $rule->getKey(),
        ])
            ->assertOk()
            ->fillForm([
                'actions' => [
                    [
                        'action_type' => AutomationActionType::SetStatus->value,
                        'action_config_json' => [
                            'status' => 'Working',
                        ],
                    ],
                ],
            ])
            ->assertSee('Set status to')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(
            'Working',
            $rule->actions()->first()->fresh()->action_config_json['status'] ?? null
        );
    }
}
