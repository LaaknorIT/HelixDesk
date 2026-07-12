<?php

namespace App\Support;

class LocalDemoLogins
{
    public static function isEnabled(): bool
    {
        return app()->environment('local');
    }

    /**
     * @return list<array{email: string, label: string}>
     */
    public static function accountsForPanel(?string $panelId): array
    {
        if (! static::isEnabled() || blank($panelId)) {
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
                fn (array $account): bool => in_array($panelId, $account['panels'], true),
            ),
        ));
    }

    public static function shouldShow(?string $panelId): bool
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

    public static function isAllowedEmail(string $email, ?string $panelId): bool
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
    public static function viewData(?string $panelId, bool $panelHasTenant = false): array
    {
        return [
            'accounts' => static::accountsForPanel($panelId),
            'password' => static::password(),
            'tenantSlug' => static::tenantSlug(),
            'panelHasTenant' => $panelHasTenant,
        ];
    }
}
