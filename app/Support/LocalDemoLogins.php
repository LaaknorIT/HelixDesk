<?php

namespace App\Support;

class LocalDemoLogins
{
    public static function isEnabled(): bool
    {
        return app()->environment('local');
    }

    /**
     * Demo accounts for one panel, or for every panel when no panel is given (the shared login page).
     *
     * @return list<array{email: string, label: string}>
     */
    public static function accountsForPanel(?string $panelId = null): array
    {
        if (! static::isEnabled()) {
            return [];
        }

        /** @var list<array{email: string, label: string, panels: list<string>}> $accounts */
        $accounts = config('helixdesk.demo_logins.accounts', []);

        return array_values(array_map(
            fn (array $account): array => [
                'email' => $account['email'],
                'label' => $account['label'],
            ],
            array_filter(
                $accounts,
                fn (array $account): bool => $panelId === null || in_array($panelId, $account['panels'], true),
            ),
        ));
    }

    public static function shouldShow(?string $panelId = null): bool
    {
        return static::accountsForPanel($panelId) !== [];
    }

    public static function password(): string
    {
        return (string) config('helixdesk.demo_logins.password', 'password');
    }

    public static function tenantSlug(): string
    {
        return (string) config('helixdesk.demo_logins.tenant_slug', 'demo-tenant');
    }

    public static function isAllowedEmail(string $email, ?string $panelId = null): bool
    {
        return collect(static::accountsForPanel($panelId))
            ->contains(fn (array $account): bool => $account['email'] === $email);
    }

    /**
     * @return array{
     *     accounts: list<array{email: string, label: string}>,
     *     password: string,
     *     tenantSlug: string,
     *     panelHasTenant: bool,
     * }
     */
    public static function viewData(?string $panelId = null, bool $panelHasTenant = true): array
    {
        return [
            'accounts' => static::accountsForPanel($panelId),
            'password' => static::password(),
            'tenantSlug' => static::tenantSlug(),
            'panelHasTenant' => $panelHasTenant,
        ];
    }
}
