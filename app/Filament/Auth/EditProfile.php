<?php

namespace App\Filament\Auth;

use App\Models\User;
use App\Support\Locales;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;

/**
 * The user's own profile in every panel: name, language and (for local accounts) password.
 *
 * E-mail is shown but not editable, because it is how mail, sign-in and identity sync find the user.
 * Users synced from an identity provider sign in there, so they get no password fields.
 */
class EditProfile extends BaseEditProfile
{
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getNameFormComponent()
                    ->disabled(fn (): bool => $this->isManagedByIdentityProvider())
                    ->helperText(fn (): ?string => $this->isManagedByIdentityProvider()
                        ? __('Your name comes from your organization\'s directory.')
                        : null),
                $this->getEmailFormComponent()->disabled()->dehydrated(false),
                $this->getLocaleFormComponent(),
                $this->getPasswordFormComponent()->hidden(fn (): bool => $this->isManagedByIdentityProvider()),
                $this->getPasswordConfirmationFormComponent(),
                $this->getCurrentPasswordFormComponent(),
            ]);
    }

    protected function getLocaleFormComponent(): Component
    {
        return Select::make('locale')
            ->label(__('Language'))
            ->options(Locales::available())
            ->placeholder(fn (): string => __('Default (:language)', ['language' => Locales::available()[Locales::default()] ?? 'English']))
            ->native(false);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if ($this->isManagedByIdentityProvider()) {
            unset($data['name'], $data['password']);
        }

        if (! Locales::isAvailable($data['locale'] ?? null)) {
            $data['locale'] = null;
        }

        return $data;
    }

    protected function getRedirectUrl(): ?string
    {
        // Reload so the new language applies to the whole page, including the menu.
        return static::getUrl();
    }

    protected function isManagedByIdentityProvider(): bool
    {
        $user = $this->getUser();

        return $user instanceof User && $user->isManagedByIdentityProvider();
    }
}
