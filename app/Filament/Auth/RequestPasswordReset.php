<?php

namespace App\Filament\Auth;

use App\Models\User;
use App\Services\Auth\HomePanelResolver;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Auth\Events\PasswordResetLinkSent;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Support\Facades\Password;
use SensitiveParameter;

/**
 * Password reset request page for local accounts.
 *
 * Users synced from an identity provider have no local password and are never sent a reset link.
 * The page answers the same way for every address, so it cannot be used to discover accounts.
 *
 * Like the login page there is one shared page, at /password-reset. The reset link points into the
 * panel the user lands in after signing in, so the reset form there accepts them.
 */
class RequestPasswordReset extends \Filament\Auth\Pages\PasswordReset\RequestPasswordReset
{
    public function mount(): void
    {
        if (request()->routeIs('filament.*.auth.password-reset.request')) {
            $this->redirect(route('password.request'));

            return;
        }

        parent::mount();
    }

    public function request(): void
    {
        try {
            $this->rateLimit(2);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return;
        }

        $data = $this->form->getState();

        $status = Password::broker(Filament::getAuthPasswordBroker())->sendResetLink(
            $this->getCredentialsFromFormData($data),
            function (CanResetPassword $user, #[SensitiveParameter] string $token): void {
                $panel = $user instanceof User ? app(HomePanelResolver::class)->panelFor($user) : null;

                if ($panel === null || ! $panel->hasPasswordReset()) {
                    return;
                }

                $notification = app(ResetPasswordNotification::class, ['token' => $token]);
                $notification->url = $panel->getResetPasswordUrl($token, $user);

                $user->notify($notification);

                event(new PasswordResetLinkSent($user));
            },
        );

        if ($status !== Password::RESET_LINK_SENT) {
            $this->getFailureNotification($status)?->send();

            return;
        }

        $this->getSentNotification($status)?->send();

        $this->form->fill();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{email: mixed, entra_object_id: null}
     */
    protected function getCredentialsFromFormData(array $data): array
    {
        return [
            ...parent::getCredentialsFromFormData($data),
            'entra_object_id' => null,
        ];
    }

    protected function getFailureNotification(string $status): ?Notification
    {
        if (in_array($status, [Password::INVALID_USER, Password::RESET_THROTTLED], true)) {
            $this->form->fill();

            return $this->getSentNotification(Password::RESET_LINK_SENT);
        }

        return parent::getFailureNotification($status);
    }
}
