<?php

namespace Tests\Feature;

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

    public function test_every_panel_login_page_links_to_password_reset(): void
    {
        foreach (['global-admin', 'agent', 'end-user'] as $panelId) {
            $this->get("/{$panelId}/login")
                ->assertOk()
                ->assertSee(route("filament.{$panelId}.auth.password-reset.request"), escape: false);

            $this->get("/{$panelId}/password-reset/request")->assertOk();
        }
    }

    public function test_end_user_receives_reset_link(): void
    {
        Notification::fake();

        $endUser = $this->endUser();

        Filament::setCurrentPanel('end-user');

        Livewire::test(RequestPasswordReset::class)
            ->fillForm(['email' => $endUser->email])
            ->call('request')
            ->assertNotified(__('passwords.sent'));

        Notification::assertSentTo($endUser, ResetPassword::class);
    }

    public function test_unknown_email_gets_the_same_response_as_a_known_one(): void
    {
        Notification::fake();

        Filament::setCurrentPanel('end-user');

        Livewire::test(RequestPasswordReset::class)
            ->fillForm(['email' => 'nobody@example.test'])
            ->call('request')
            ->assertNotified(__('passwords.sent'));

        Notification::assertNothingSent();
    }

    public function test_repeated_request_is_not_revealed_as_throttled(): void
    {
        $endUser = $this->endUser();

        Filament::setCurrentPanel('end-user');

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

    public function test_user_without_access_to_the_panel_gets_no_reset_link(): void
    {
        Notification::fake();

        $agent = User::factory()->create([
            'tenant_id' => Tenant::factory()->create()->id,
            'type' => 'agent',
        ]);

        Filament::setCurrentPanel('end-user');

        Livewire::test(RequestPasswordReset::class)
            ->fillForm(['email' => $agent->email])
            ->call('request')
            ->assertNotified(__('passwords.sent'));

        Notification::assertNothingSent();
    }

    private function endUser(): User
    {
        return User::factory()->create([
            'tenant_id' => Tenant::factory()->create()->id,
            'type' => 'end_user',
        ]);
    }
}
