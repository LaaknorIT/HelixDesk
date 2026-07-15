<?php

namespace App\Support;

use App\Models\Tenant;

final class TenantIntegrationSettings
{
    public const AUTO_RETIRE_ASSETS_WHEN_ALL_PROVIDERS_DROP = 'auto_retire_assets_when_all_providers_drop';

    public const RETIREMENT_GRACE_DAYS = 'retirement_grace_days';

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            self::AUTO_RETIRE_ASSETS_WHEN_ALL_PROVIDERS_DROP => false,
            self::RETIREMENT_GRACE_DAYS => (int) config('helixdesk_integrations.identity.retirement_grace_days', 30),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function forTenant(Tenant $tenant): array
    {
        $stored = is_array($tenant->integration_settings) ? $tenant->integration_settings : [];

        return array_merge(self::defaults(), $stored);
    }

    public static function autoRetireEnabled(Tenant $tenant): bool
    {
        return (bool) self::forTenant($tenant)[self::AUTO_RETIRE_ASSETS_WHEN_ALL_PROVIDERS_DROP];
    }

    public static function retirementGraceDays(Tenant $tenant): int
    {
        return max(1, (int) self::forTenant($tenant)[self::RETIREMENT_GRACE_DAYS]);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public static function update(Tenant $tenant, array $settings): void
    {
        $merged = array_merge(self::forTenant($tenant), $settings);
        $tenant->integration_settings = [
            self::AUTO_RETIRE_ASSETS_WHEN_ALL_PROVIDERS_DROP => (bool) ($merged[self::AUTO_RETIRE_ASSETS_WHEN_ALL_PROVIDERS_DROP] ?? false),
            self::RETIREMENT_GRACE_DAYS => max(1, (int) ($merged[self::RETIREMENT_GRACE_DAYS] ?? self::defaults()[self::RETIREMENT_GRACE_DAYS])),
        ];
        $tenant->save();
    }
}
