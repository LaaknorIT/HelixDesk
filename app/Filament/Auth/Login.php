<?php

namespace App\Filament\Auth;

use App\Support\LocalDemoLogins;
use Filament\Facades\Filament;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\View\PanelsRenderHook;

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
                $this->getLocalDemoLoginsComponent(),
            ]);
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
