<?php

use App\Filament\Auth\Login;
use App\Filament\Auth\RequestPasswordReset;
use App\Http\Controllers\Auth\MicrosoftSignInController;
use App\Http\Controllers\PublicPageController;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;

Route::get('/', PublicPageController::class)->name('home');

/*
 * One login page for agents, end users and global admins. It runs in a panel's context so the
 * Filament page works; after sign-in each user is sent to the panel they should work in.
 */
Route::middleware(Filament::getPanel(Login::SHARED_PANEL)->getMiddleware())->group(function (): void {
    Route::get('/login', Login::class)->name('login');
    Route::get('/password-reset', RequestPasswordReset::class)->name('password.request');
});

Route::get('/auth/microsoft/redirect', [MicrosoftSignInController::class, 'redirect'])
    ->name('auth.microsoft.redirect');

Route::get('/auth/microsoft/callback', [MicrosoftSignInController::class, 'callback'])
    ->name('auth.microsoft.callback');
