<?php

namespace Tests\Feature;

use App\Enums\WorkItemPriority;
use App\Enums\WorkItemType;
use App\Filament\Agent\Resources\WorkItems\Pages\ListWorkItems;
use App\Filament\Auth\EditProfile;
use App\Filament\EndUser\Resources\WorkItems\Pages\ListEndUserWorkItems;
use App\Models\Organization;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkItem;
use App\Services\WorkItemWorkflowService;
use App\Support\Locales;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Tests\TestCase;

class LocalizationAndTicketIntakeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->agent = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'type' => 'agent',
        ]);

        setPermissionsTeamId($this->tenant->id);
        $this->agent->assignRole('Agent');
        setPermissionsTeamId(null);
    }

    public function test_every_translation_file_is_valid_and_keeps_placeholders(): void
    {
        foreach (array_keys(Locales::available()) as $locale) {
            if ($locale === 'en') {
                continue;
            }

            $path = lang_path($locale.'.json');
            $this->assertFileExists($path);

            $translations = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            $this->assertIsArray($translations);

            foreach ($translations as $key => $value) {
                $this->assertSame(
                    $this->placeholders((string) $key),
                    $this->placeholders((string) $value),
                    "Placeholders differ for [{$locale}] \"{$key}\".",
                );
            }
        }
    }

    public function test_english_is_the_default_language(): void
    {
        $this->assertSame('en', Locales::default());
        $this->assertSame('en', Locales::forUser($this->agent));

        $this->actingAs($this->agent)
            ->get(route('filament.agent.resources.work-items.index', ['tenant' => $this->tenant]))
            ->assertOk()
            ->assertSee('New ticket');
    }

    public function test_user_sees_the_panel_in_their_chosen_language(): void
    {
        $this->agent->forceFill(['locale' => 'nb'])->save();

        $this->actingAs($this->agent)
            ->get(route('filament.agent.resources.work-items.index', ['tenant' => $this->tenant]))
            ->assertOk()
            ->assertSee('Ny sak')
            ->assertDontSee('New ticket');

        $this->assertSame('nb', $this->agent->preferredLocale());
    }

    public function test_unknown_language_falls_back_to_english(): void
    {
        $this->agent->forceFill(['locale' => 'xx'])->save();

        $this->assertSame('en', Locales::forUser($this->agent->fresh()));
    }

    public function test_user_can_choose_language_in_profile(): void
    {
        $this->actingAs($this->agent);
        Filament::setCurrentPanel('agent');

        Livewire::test(EditProfile::class)
            ->fillForm(['locale' => 'nb'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('nb', $this->agent->fresh()->locale);
    }

    public function test_priorities_are_stored_in_one_vocabulary(): void
    {
        $this->actingAs($this->agent);

        $workItem = app(WorkItemWorkflowService::class)->createWorkItem([
            'title' => 'Server down',
            'description' => 'Nothing answers.',
            'priority' => 'Critical',
            'type' => WorkItemType::Incident,
        ], $this->agent);

        $this->assertSame(WorkItemPriority::Urgent->value, $workItem->fresh()->priority);

        $workItem->update(['priority' => 'Medium']);
        $this->assertSame(WorkItemPriority::Normal->value, $workItem->fresh()->priority);
    }

    public function test_agent_can_register_a_ticket_on_behalf_of_an_end_user(): void
    {
        $organization = Organization::factory()->create(['tenant_id' => $this->tenant->id]);
        $requester = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'organization_id' => $organization->id,
            'type' => 'end_user',
        ]);

        $this->actingAs($this->agent);
        Filament::setCurrentPanel('agent');
        Filament::setTenant($this->tenant);

        Livewire::test(ListWorkItems::class)
            ->callAction('createWorkItem', [
                'type' => WorkItemType::ServiceRequest->value,
                'priority' => WorkItemPriority::High->value,
                'reported_by_user_id' => $requester->id,
                'title' => 'New laptop for Kari',
                'description' => 'Starts on Monday.',
                'assigned_agent_id' => $this->agent->id,
            ])
            ->assertHasNoActionErrors();

        $workItem = WorkItem::query()->where('title', 'New laptop for Kari')->firstOrFail();

        $this->assertSame($requester->id, $workItem->reported_by_user_id);
        $this->assertSame($organization->id, $workItem->organization_id);
        $this->assertSame(WorkItemPriority::High->value, $workItem->priority);
        $this->assertSame($this->agent->id, $workItem->assigned_agent_id);
    }

    public function test_requester_must_belong_to_the_same_tenant(): void
    {
        $stranger = User::factory()->create([
            'tenant_id' => Tenant::factory()->create()->id,
            'type' => 'end_user',
        ]);

        $this->actingAs($this->agent);

        $this->expectException(UnprocessableEntityHttpException::class);

        app(WorkItemWorkflowService::class)->createWorkItem([
            'title' => 'Not mine',
            'description' => 'Should fail.',
            'reported_by_user_id' => $stranger->id,
        ], $this->agent);
    }

    public function test_end_user_can_report_an_issue_from_the_portal(): void
    {
        $endUser = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'type' => 'end_user',
        ]);

        $this->actingAs($endUser);
        Filament::setCurrentPanel('end-user');
        Filament::setTenant($this->tenant);

        Livewire::test(ListEndUserWorkItems::class)
            ->callAction('createWorkItem', [
                'type' => WorkItemType::Incident->value,
                'title' => 'Cannot print',
                'description' => 'The printer on floor 2 says offline.',
                'impact' => 'me',
            ])
            ->assertHasNoActionErrors();

        $workItem = WorkItem::query()->where('title', 'Cannot print')->firstOrFail();

        $this->assertSame($endUser->id, $workItem->reported_by_user_id);
        $this->assertSame('The printer on floor 2 says offline.', $workItem->description);
    }

    /**
     * @return list<string>
     */
    private function placeholders(string $text): array
    {
        preg_match_all('/:[a-z_]+/', $text, $matches);
        $found = $matches[0];
        sort($found);

        return $found;
    }
}
