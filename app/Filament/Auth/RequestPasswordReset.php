<?php

namespace App\Filament\Auth;

use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Password;

/**
 * Password reset request page for local accounts.
 *
 * Users synced from an identity provider have no local password and are never sent a reset link.
 * The page answers the same way for every address, so it cannot be used to discover accounts.
 */
class RequestPasswordReset extends \Filament\Auth\Pages\PasswordReset\RequestPasswordReset
{
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
