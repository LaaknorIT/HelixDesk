<?php

namespace App\Filament\Auth;

use App\Models\OrganizationIntegration;
use App\Models\User;
use App\Services\Auth\EntraSignInService;
use App\Services\Auth\HomePanelResolver;
use App\Support\LocalDemoLogins;
use App\Support\OrganizationIntegrationSettings;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\HtmlString;

/**
 * The one login page for every panel, served at /login.
 *
 * Each panel keeps a login route so Filament can send guests to it, but those routes forward to /login.
 * After sign-in the user lands in the panel {@see HomePanelResolver} picks for them.
 */
class Login extends \Filament\Auth\Pages\Login
{
    /**
     * Panel whose context the shared login page runs in. It only decides styling and the auth guard.
     */
    public const SHARED_PANEL = 'agent';

    public function mount(): void
    {
        if (request()->routeIs('filament.*.auth.login')) {
            $this->redirect(route('login'));

            return;
        }

        $user = Filament::auth()->user();

        if ($user instanceof User) {
            $destination = app(HomePanelResolver::class)->destinationFor($user, session()->pull('url.intended'));

            if ($destination !== null) {
                $this->redirect($destination);

                return;
            }
        }

        $this->form->fill();
    }

    public function getHeading(): string|HtmlString
    {
        return __('Sign in to :name', ['name' => config('app.name', 'HelixDesk')]);
    }

    protected function isUserAllowedToAccessPanel(Authenticatable $user): bool
    {
        return $user instanceof User && app(HomePanelResolver::class)->panelFor($user) !== null;
    }

    protected function getPasswordFormComponent(): Component
    {
        /** @var TextInput $component */
        $component = parent::getPasswordFormComponent();

        return $component->hint(new HtmlString(Blade::render(
            '<x-filament::link :href="$url" tabindex="-1">{{ __(\'filament-panels::auth/pages/login.actions.request_password_reset.label\') }}</x-filament::link>',
            ['url' => route('password.request')],
        )));
    }

    public function selectDemoAccount(string $email): void
    {
        if (! LocalDemoLogins::isAllowedEmail($email)) {
            return;
        }

        $this->data['email'] = $email;
        $this->data['password'] = LocalDemoLogins::password();
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                RenderHook::make(PanelsRenderHook::AUTH_LOGIN_FORM_BEFORE),
                $this->getFormContentComponent(),
                $this->getMultiFactorChallengeFormContentComponent(),
                RenderHook::make(PanelsRenderHook::AUTH_LOGIN_FORM_AFTER),
                $this->getMicrosoftSignInComponent(),
                $this->getLocalDemoLoginsComponent(),
            ]);
    }

    /**
     * Send users synced from Microsoft Entra to their own Entra tenant to sign in.
     */
    public function signInWithMicrosoft(): void
    {
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return;
        }

        $email = trim((string) ($this->data['email'] ?? ''));

        Validator::make(['data' => ['email' => $email]], ['data.email' => ['required', 'email']])
            ->setAttributeNames(['data.email' => __('filament-panels::auth/pages/login.form.email.label')])
            ->validate();

        $signIn = app(EntraSignInService::class);
        $target = $signIn->resolveForEmail($email);

        if ($target === null) {
            $this->addError('data.email', __('Microsoft sign-in is not available for this e-mail address.'));

            return;
        }

        session()->put(EntraSignInService::SESSION_KEY, [
            'user_id' => $target['user']->getKey(),
            'integration_id' => (string) $target['integration']->getKey(),
            'panel' => $target['panel']->getId(),
        ]);

        $this->redirect(route('auth.microsoft.redirect'));
    }

    protected function getMicrosoftSignInComponent(): Component
    {
        return Actions::make([
            Action::make('signInWithMicrosoft')
                ->label(__('Sign in with Microsoft'))
                ->color('gray')
                ->outlined()
                ->extraAttributes(['class' => 'w-full'])
                ->action(fn () => $this->signInWithMicrosoft()),
        ])
            ->fullWidth()
            ->alignment(Alignment::Center)
            ->visible(fn (): bool => OrganizationIntegration::query()
                ->withoutGlobalScope('tenant')
                ->where('type', OrganizationIntegrationSettings::TYPE_MICROSOFT_ENTRA)
                ->exists());
    }

    protected function getLocalDemoLoginsComponent(): Component
    {
        return View::make('filament.auth.local-demo-logins')
            ->visible(fn (): bool => LocalDemoLogins::shouldShow())
            ->viewData(fn (): array => LocalDemoLogins::viewData());
    }
}
