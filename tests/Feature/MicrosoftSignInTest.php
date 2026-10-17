<?php

namespace Tests\Feature;

use App\Filament\Auth\Login;
use App\Models\Organization;
use App\Models\OrganizationIntegration;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\EntraSignInService;
use App\Support\OrganizationIntegrationSettings;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Two\User as SocialiteUser;
use Livewire\Livewire;
use Mockery;
use SocialiteProviders\Azure\Provider as AzureProvider;
use Tests\TestCase;

class MicrosoftSignInTest extends TestCase
{
    use RefreshDatabase;

    private const OBJECT_ID = '11111111-1111-1111-1111-111111111111';

    public function test_login_page_offers_microsoft_sign_in_only_when_an_entra_integration_exists(): void
    {
        $this->get('/end-user/login')->assertOk()->assertDontSee('Sign in with Microsoft');

        $this->syncedEndUser();

        $this->get('/end-user/login')->assertOk()->assertSee('Sign in with Microsoft');
    }

    public function test_synced_user_is_sent_to_their_own_entra_tenant(): void
    {
        [$user, $integration] = $this->syncedEndUser();

        Filament::setCurrentPanel('end-user');

        Livewire::test(Login::class)
            ->set('data.email', $user->email)
            ->call('signInWithMicrosoft')
            ->assertHasNoErrors()
            ->assertRedirect(route('auth.microsoft.redirect'));

        $this->assertSame([
            'user_id' => $user->getKey(),
            'integration_id' => (string) $integration->getKey(),
            'panel' => 'end-user',
        ], session(EntraSignInService::SESSION_KEY));

        $redirect = $this->get(route('auth.microsoft.redirect'))
            ->assertRedirect()
            ->headers->get('Location');

        $this->assertStringStartsWith('https://login.microsoftonline.com/customer-tenant-guid/oauth2/v2.0/authorize', $redirect);
        $this->assertStringContainsString('client_id=customer-client-guid', $redirect);
        $this->assertStringContainsString('login_hint='.urlencode($user->email), $redirect);
        $this->assertStringContainsString(urlencode(route('auth.microsoft.callback')), $redirect);
    }

    public function test_unknown_or_local_email_cannot_use_microsoft_sign_in(): void
    {
        $this->syncedEndUser();
        $localUser = User::factory()->create([
            'tenant_id' => Tenant::factory()->create()->id,
            'type' => 'end_user',
        ]);

        Filament::setCurrentPanel('end-user');

        foreach (['nobody@example.test', $localUser->email] as $email) {
            Livewire::test(Login::class)
                ->set('data.email', $email)
                ->call('signInWithMicrosoft')
                ->assertHasErrors(['data.email'])
                ->assertNoRedirect();
        }

        $this->assertNull(session(EntraSignInService::SESSION_KEY));
    }

    public function test_disabled_integration_cannot_be_used_to_sign_in(): void
    {
        [$user, $integration] = $this->syncedEndUser();
        $integration->update(['enabled' => false]);

        Filament::setCurrentPanel('end-user');

        Livewire::test(Login::class)
            ->set('data.email', $user->email)
            ->call('signInWithMicrosoft')
            ->assertHasErrors(['data.email'])
            ->assertNoRedirect();
    }

    public function test_user_synced_before_integration_was_recorded_is_found_through_their_organization(): void
    {
        [$user, $integration] = $this->syncedEndUser();

        $department = Organization::factory()->create([
            'tenant_id' => $user->tenant_id,
            'parent_id' => $integration->organization_id,
        ]);
        $user->update(['identity_integration_id' => null, 'organization_id' => $department->id]);

        $found = app(EntraSignInService::class)->integrationForUser($user->fresh());

        $this->assertTrue($integration->is($found));
    }

    public function test_callback_signs_in_the_matching_microsoft_account(): void
    {
        [$user, $integration] = $this->syncedEndUser();
        $this->fakeMicrosoftAccount(strtoupper(self::OBJECT_ID));

        $this->withSession([EntraSignInService::SESSION_KEY => [
            'user_id' => $user->getKey(),
            'integration_id' => (string) $integration->getKey(),
            'panel' => 'end-user',
        ]])
            ->get(route('auth.microsoft.callback', ['code' => 'code', 'state' => 'state']))
            ->assertRedirect();

        $this->assertAuthenticatedAs($user);
        $this->assertNull(session(EntraSignInService::SESSION_KEY));
    }

    public function test_callback_rejects_a_different_microsoft_account(): void
    {
        [$user, $integration] = $this->syncedEndUser();
        $this->fakeMicrosoftAccount('22222222-2222-2222-2222-222222222222');

        $this->withSession([EntraSignInService::SESSION_KEY => [
            'user_id' => $user->getKey(),
            'integration_id' => (string) $integration->getKey(),
            'panel' => 'end-user',
        ]])
            ->get(route('auth.microsoft.callback', ['code' => 'code', 'state' => 'state']))
            ->assertRedirect(route('filament.end-user.auth.login'));

        $this->assertGuest();
    }

    public function test_callback_without_a_started_sign_in_does_nothing(): void
    {
        $this->syncedEndUser();
        $this->fakeMicrosoftAccount(self::OBJECT_ID);

        $this->get(route('auth.microsoft.callback', ['code' => 'code', 'state' => 'state']))
            ->assertRedirect('/');

        $this->assertGuest();
    }

    private function fakeMicrosoftAccount(string $objectId): void
    {
        $provider = Mockery::mock(AzureProvider::class);
        $provider->shouldReceive('user')->andReturn((new SocialiteUser)->map(['id' => $objectId]));

        $signIn = Mockery::mock(EntraSignInService::class)->makePartial();
        $signIn->shouldReceive('provider')->andReturn($provider);

        $this->app->instance(EntraSignInService::class, $signIn);
    }

    /**
     * @return array{0: User, 1: OrganizationIntegration}
     */
    private function syncedEndUser(): array
    {
        $tenant = Tenant::factory()->create();
        $customer = Organization::factory()->create(['tenant_id' => $tenant->id]);

        $integration = OrganizationIntegration::query()->create([
            'id' => 'entra-customer',
            'tenant_id' => $tenant->id,
            'organization_id' => $customer->id,
            'type' => OrganizationIntegrationSettings::TYPE_MICROSOFT_ENTRA,
            'enabled' => true,
            'config' => [
                'enabled' => true,
                'azure_tenant_id' => 'customer-tenant-guid',
                'application_client_id' => 'customer-client-guid',
            ],
            'credentials' => ['client_secret' => 'secret'],
            'scope_key' => '',
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'organization_id' => $customer->id,
            'type' => 'end_user',
            'entra_object_id' => self::OBJECT_ID,
            'identity_integration_id' => $integration->getKey(),
            'password' => null,
        ]);

        return [$user, $integration];
    }
}
