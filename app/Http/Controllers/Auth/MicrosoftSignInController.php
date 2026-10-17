<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\OrganizationIntegration;
use App\Models\User;
use App\Services\Auth\EntraSignInService;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Panel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;
use Throwable;

/**
 * "Sign in with Microsoft" for users synced from Microsoft Entra, started from a panel login page.
 */
class MicrosoftSignInController extends Controller
{
    /**
     * Send the browser to the Entra tenant chosen on the login page.
     */
    public function redirect(Request $request, EntraSignInService $signIn): SymfonyRedirectResponse
    {
        $pending = $this->pendingSignIn($request->session()->get(EntraSignInService::SESSION_KEY), $signIn);

        if ($pending === null) {
            return redirect('/');
        }

        if ($pending['config'] === null) {
            $request->session()->forget(EntraSignInService::SESSION_KEY);

            return $this->failed($pending['panel']);
        }

        return $signIn->provider($pending['config'])
            ->with(['login_hint' => $pending['user']->email])
            ->redirect();
    }

    /**
     * Finish sign-in after Microsoft sends the browser back.
     */
    public function callback(Request $request, EntraSignInService $signIn): RedirectResponse
    {
        $pending = $this->pendingSignIn($request->session()->pull(EntraSignInService::SESSION_KEY), $signIn);

        if ($pending === null) {
            return redirect('/');
        }

        ['user' => $user, 'panel' => $panel, 'config' => $config] = $pending;

        if ($config === null) {
            return $this->failed($panel);
        }

        try {
            $remoteUser = $signIn->provider($config)->user();
        } catch (Throwable $exception) {
            Log::warning('Microsoft sign-in callback failed.', [
                'user_id' => $user->getKey(),
                'error' => $exception->getMessage(),
            ]);

            return $this->failed($panel);
        }

        if (strcasecmp((string) $remoteUser->getId(), (string) $user->entra_object_id) !== 0) {
            Log::warning('Microsoft sign-in returned a different account than the one requested.', [
                'user_id' => $user->getKey(),
            ]);

            return $this->failed($panel);
        }

        $panel->auth()->login($user);
        $request->session()->regenerate();

        return redirect()->intended($panel->getUrl());
    }

    /**
     * Rebuild the sign-in started on the login page from the session.
     *
     * @return array{user: User, panel: Panel, config: array{azure_tenant_id: string, application_client_id: string, client_secret: string}|null}|null
     */
    private function pendingSignIn(mixed $pending, EntraSignInService $signIn): ?array
    {
        if (! is_array($pending)) {
            return null;
        }

        $panelId = (string) ($pending['panel'] ?? '');
        $panel = $panelId !== '' ? rescue(fn (): Panel => Filament::getPanel($panelId), null, report: false) : null;
        $user = User::query()->find($pending['user_id'] ?? null);

        if ($panel === null || $user === null) {
            return null;
        }

        $integration = OrganizationIntegration::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $user->tenant_id)
            ->find($pending['integration_id'] ?? null);

        $usable = $integration !== null && $user->isManagedByIdentityProvider() && $user->canAccessPanel($panel);

        return [
            'user' => $user,
            'panel' => $panel,
            'config' => $usable ? $signIn->signInConfig($integration) : null,
        ];
    }

    private function failed(Panel $panel): RedirectResponse
    {
        Notification::make()
            ->title(__('Microsoft sign-in failed'))
            ->body(__('Sign in again with the Microsoft account that belongs to your e-mail address.'))
            ->danger()
            ->send();

        return redirect($panel->getLoginUrl() ?? '/');
    }
}
