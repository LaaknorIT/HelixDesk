<?php

namespace App\Filament\Auth;

use App\Models\OrganizationIntegration;
use App\Services\Auth\EntraSignInService;
use App\Support\LocalDemoLogins;
use App\Support\OrganizationIntegrationSettings;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Validator;

class Login extends \Filament\Auth\Pages\Login
{
    public function selectDemoAccount(string $email): void
    {
        $panelId = Filament::getCurrentPanel()?->getId();

        if (! LocalDemoLogins::isAllowedEmail($email, $panelId)) {
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

        $panel = Filament::getCurrentOrDefaultPanel();
        $signIn = app(EntraSignInService::class);
        $target = $signIn->resolveForEmail($email, $panel);

        if ($target === null) {
            $this->addError('data.email', __('Microsoft sign-in is not available for this e-mail address.'));

            return;
        }

        session()->put(EntraSignInService::SESSION_KEY, [
            'user_id' => $target['user']->getKey(),
            'integration_id' => (string) $target['integration']->getKey(),
            'panel' => $panel->getId(),
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
            ->visible(fn (): bool => LocalDemoLogins::shouldShow(Filament::getCurrentPanel()?->getId()))
            ->viewData(fn (): array => LocalDemoLogins::viewData(
                Filament::getCurrentPanel()?->getId(),
                Filament::getCurrentPanel()?->hasTenancy() ?? false,
            ));
    }
}
