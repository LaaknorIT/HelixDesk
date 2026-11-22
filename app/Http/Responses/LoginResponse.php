<?php

namespace App\Http\Responses;

use App\Models\User;
use App\Services\Auth\HomePanelResolver;
use Filament\Auth\Http\Responses\Contracts\LoginResponse as LoginResponseContract;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Livewire\Features\SupportRedirects\Redirector;

/**
 * Sends a user who signed in on the shared login page to the panel they should work in.
 */
class LoginResponse implements LoginResponseContract
{
    public function __construct(private HomePanelResolver $homePanels) {}

    public function toResponse($request): RedirectResponse|Redirector
    {
        $user = Filament::auth()->user();
        $intendedUrl = session()->pull('url.intended');

        $destination = $user instanceof User
            ? $this->homePanels->destinationFor($user, is_string($intendedUrl) ? $intendedUrl : null)
            : null;

        return redirect()->to($destination ?? Filament::getUrl());
    }
}
