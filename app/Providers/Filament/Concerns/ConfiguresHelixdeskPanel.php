<?php

namespace App\Providers\Filament\Concerns;

use App\Filament\Auth\EditProfile;
use App\Filament\Auth\Login;
use App\Filament\Auth\RequestPasswordReset;
use App\Filament\LocalAvatarProvider;
use App\Http\Middleware\ApplyUserLocale;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * What every HelixDesk panel shares: sign-in, profile with language choice, brand colour, theme,
 * content width, local avatars and the middleware stack.
 *
 * Content uses the full width of the screen so wide monitors are not left with empty margins and
 * horizontally scrolling tables or step bars. Panels with mostly running text may narrow it again.
 */
trait ConfiguresHelixdeskPanel
{
    protected function configureHelixdeskPanel(Panel $panel): Panel
    {
        return $panel
            ->login(Login::class)
            ->passwordReset(RequestPasswordReset::class)
            ->profile(EditProfile::class, isSimple: false)
            ->colors([
                'primary' => Color::Indigo,
            ])
            ->viteTheme('resources/css/filament/theme.css')
            ->maxContentWidth(Width::Full)
            ->defaultAvatarProvider(LocalAvatarProvider::class)
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                ApplyUserLocale::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
