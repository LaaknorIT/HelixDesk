<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Locales;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Show the interface in the signed-in user's language, or the default language (English) for guests.
 */
class ApplyUserLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        App::setLocale(Locales::forUser($user instanceof User ? $user : null));

        return $next($request);
    }
}
