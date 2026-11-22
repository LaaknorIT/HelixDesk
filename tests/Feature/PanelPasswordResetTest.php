<?php

namespace Tests\Feature;

use App\Filament\Auth\Login;
use App\Filament\Auth\RequestPasswordReset;
use App\Models\Tenant;
use App\Models\User;
use Filament\Auth\Notifications\ResetPassword;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class PanelPasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_shared_login_page_links_to_the_shared_password_reset_page(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee(route('password.request'), escape: false);

        $this->get('/password-reset')->assertOk();

        foreach (['global-admin', 'agent', 'end-user'] as $panelId) {
            $this->get("/{$panelId}/password-reset/request")->assertRedirect(route('password.request'));
        }
    }

    public function test_end_user_receives_reset_link(): void
    {
        Notification::fake();

        $endUser = $this->endUser();

        Filament::setCurrentPanel(Login::SHARED_PANEL);

        Livewire::test(RequestPasswordReset::class)
            ->fillForm(['email' => $endUser->email])
            ->call('request')
            ->assertNotified(__('passwords.sent'));

        Notification::assertSentTo(
            $endUser,
            ResetPassword::class,
            fn (ResetPassword $notification): bool => str_starts_with($notification->url, url('/end-user/password-reset/reset')),
        );
    }

    public function test_agent_receives_a_reset_link_into_the_agent_panel(): void
    {
        Notification::fake();

        $agent = User::factory()->create([
            'tenant_id' => Tenant::factory()->create()->id,
            'type' => 'agent',
        ]);

        Filament::setCurrentPanel(Login::SHARED_PANEL);

        Livewire::test(RequestPasswordReset::class)
            ->fillForm(['email' => $agent->email])
            ->call('request')
            ->assertNotified(__('passwords.sent'));

        Notification::assertSentTo(
            $agent,
            ResetPassword::class,
            fn (ResetPassword $notification): bool => str_starts_with($notification->url, url('/agent/password-reset/reset')),
        );
    }

    public function test_unknown_email_gets_the_same_response_as_a_known_one(): void
    {
        Notification::fake();

        Filament::setCurrentPanel(Login::SHARED_PANEL);

        Livewire::test(RequestPasswordReset::class)
            ->fillForm(['email' => 'nobody@example.test'])
            ->call('request')
            ->assertNotified(__('passwords.sent'));

        Notification::assertNothingSent();
    }

    public function test_repeated_request_is_not_revealed_as_throttled(): void
    {
        $endUser = $this->endUser();

        Filament::setCurrentPanel(Login::SHARED_PANEL);

        Notification::fake();

        Livewire::test(RequestPasswordReset::class)
            ->fillForm(['email' => $endUser->email])
            ->call('request');

        Livewire::test(RequestPasswordReset::class)
            ->fillForm(['email' => $endUser->email])
            ->call('request')
            ->assertNotified(__('passwords.sent'));

        Notification::assertSentToTimes($endUser, ResetPassword::class, 1);
    }

    public function test_user_without_access_to_any_panel_gets_no_reset_link(): void
    {
        Notification::fake();

        $agent = User::factory()->create([
            'tenant_id' => null,
            'type' => 'agent',
            'is_admin' => false,
        ]);

        Filament::setCurrentPanel(Login::SHARED_PANEL);

        Livewire::test(RequestPasswordReset::class)
            ->fillForm(['email' => $agent->email])
            ->call('request')
            ->assertNotified(__('passwords.sent'));

        Notification::assertNothingSent();
    }

    public function test_user_synced_from_identity_provider_gets_no_reset_link(): void
    {
        Notification::fake();

        $syncedUser = User::factory()->create([
            'tenant_id' => Tenant::factory()->create()->id,
            'type' => 'end_user',
            'entra_object_id' => '11111111-1111-1111-1111-111111111111',
            'password' => null,
        ]);

        Filament::setCurrentPanel(Login::SHARED_PANEL);

        Livewire::test(RequestPasswordReset::class)
            ->fillForm(['email' => $syncedUser->email])
            ->call('request')
            ->assertNotified(__('passwords.sent'));

        Notification::assertNothingSent();
    }

    public function test_user_synced_from_identity_provider_cannot_sign_in_with_a_password(): void
    {
        $syncedUser = User::factory()->create([
            'tenant_id' => Tenant::factory()->create()->id,
            'type' => 'end_user',
            'entra_object_id' => '11111111-1111-1111-1111-111111111111',
            'password' => null,
        ]);

        $this->assertTrue($syncedUser->isManagedByIdentityProvider());
        $this->assertFalse(auth()->validate(['email' => $syncedUser->email, 'password' => '']));
        $this->assertFalse(auth()->validate(['email' => $syncedUser->email, 'password' => 'password']));
    }

    private function endUser(): User
    {
        return User::factory()->create([
            'tenant_id' => Tenant::factory()->create()->id,
            'type' => 'end_user',
        ]);
    }
}
