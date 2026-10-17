<?php

use App\Http\Controllers\Auth\MicrosoftSignInController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/auth/microsoft/redirect', [MicrosoftSignInController::class, 'redirect'])
    ->name('auth.microsoft.redirect');

Route::get('/auth/microsoft/callback', [MicrosoftSignInController::class, 'callback'])
    ->name('auth.microsoft.callback');
