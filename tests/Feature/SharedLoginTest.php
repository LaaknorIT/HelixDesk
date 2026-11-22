<?php

namespace Tests\Feature;

use App\Filament\Auth\Login;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\HomePanelResolver;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class SharedLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_panel_login_page_forwards_to_the_shared_login_page(): void
    {
        $this->get('/login')->assertOk()->assertSee('Sign in to');

        foreach (['global-admin', 'agent', 'end-user'] as $panelId) {
            $this->get("/{$panelId}/login")->assertRedirect(route('login'));
        }
    }

    public function test_agent_lands_in_the_agent_panel(): void
    {
        $tenant = Tenant::factory()->create();
        $agent = User::factory()->create(['tenant_id' => $tenant->id, 'type' => 'agent']);

        $this->signIn($agent)->assertRedirect(url("/agent/{$tenant->slug}"));

        $this->assertAuthenticatedAs($agent);
    }

    public function test_end_user_lands_in_the_end_user_panel(): void
    {
        $tenant = Tenant::factory()->create();
        $endUser = User::factory()->create(['tenant_id' => $tenant->id, 'type' => 'end_user']);

        $this->signIn($endUser)->assertRedirect(url("/end-user/{$tenant->slug}"));

        $this->assertAuthenticatedAs($endUser);
    }

    public function test_global_admin_without_tenant_lands_in_the_global_admin_panel(): void
    {
        $admin = User::factory()->create(['tenant_id' => null, 'type' => 'global_admin', 'is_admin' => true]);

        $this->signIn($admin)->assertRedirect(url('/global-admin'));
    }

    public function test_user_with_both_agent_and_end_user_access_lands_in_the_agent_panel(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'type' => 'end_user', 'is_admin' => true]);

        setPermissionsTeamId($tenant->id);
        $user->assignRole('Tenant Admin');
        setPermissionsTeamId(null);

        $user = $user->fresh();
        $this->assertTrue($user->canAccessPanel(Filament::getPanel('end-user')));
        $this->assertTrue($user->canAccessPanel(Filament::getPanel('agent')));

        $this->signIn($user)->assertRedirect(url("/agent/{$tenant->slug}"));
    }

    public function test_user_without_any_panel_cannot_sign_in(): void
    {
        $user = User::factory()->create(['tenant_id' => null, 'type' => 'agent', 'is_admin' => false]);

        Filament::setCurrentPanel(Login::SHARED_PANEL);

        Livewire::test(Login::class)
            ->fillForm(['email' => $user->email, 'password' => 'password'])
            ->call('authenticate')
            ->assertHasErrors(['data.email']);

        $this->assertGuest();
    }

    public function test_page_the_user_was_trying_to_open_is_kept_when_they_may_use_it(): void
    {
        $tenant = Tenant::factory()->create();
        $endUser = User::factory()->create(['tenant_id' => $tenant->id, 'type' => 'end_user']);
        $intended = url("/end-user/{$tenant->slug}/end-user-work-items");

        session()->put('url.intended', $intended);

        $this->signIn($endUser)->assertRedirect($intended);
    }

    public function test_page_in_a_panel_the_user_may_not_use_is_ignored(): void
    {
        $tenant = Tenant::factory()->create();
        $endUser = User::factory()->create(['tenant_id' => $tenant->id, 'type' => 'end_user']);

        session()->put('url.intended', url("/agent/{$tenant->slug}"));

        $this->signIn($endUser)->assertRedirect(url("/end-user/{$tenant->slug}"));
    }

    public function test_signed_in_user_opening_the_login_page_goes_to_their_workspace(): void
    {
        $tenant = Tenant::factory()->create();
        $agent = User::factory()->create(['tenant_id' => $tenant->id, 'type' => 'agent']);

        $this->actingAs($agent)->get('/login')->assertRedirect(url("/agent/{$tenant->slug}"));
    }

    public function test_external_intended_url_is_never_used(): void
    {
        $tenant = Tenant::factory()->create();
        $agent = User::factory()->create(['tenant_id' => $tenant->id, 'type' => 'agent']);

        $this->assertSame(
            url("/agent/{$tenant->slug}"),
            app(HomePanelResolver::class)->destinationFor($agent, 'https://evil.example/agent/'.$tenant->slug),
        );
    }

    private function signIn(User $user): Testable
    {
        Filament::setCurrentPanel(Login::SHARED_PANEL);

        return Livewire::test(Login::class)
            ->fillForm(['email' => $user->email, 'password' => 'password'])
            ->call('authenticate')
            ->assertHasNoErrors();
    }
}
