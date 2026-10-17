<?php

namespace App\Filament\Auth;

use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Password;

/**
 * Password reset request page that answers the same way whether or not the e-mail
 * address belongs to an account, so the form cannot be used to discover users.
 */
class RequestPasswordReset extends \Filament\Auth\Pages\PasswordReset\RequestPasswordReset
{
    protected function getFailureNotification(string $status): ?Notification
    {
        if (in_array($status, [Password::INVALID_USER, Password::RESET_THROTTLED], true)) {
            $this->form->fill();

            return $this->getSentNotification(Password::RESET_LINK_SENT);
        }

        return parent::getFailureNotification($status);
    }
}
