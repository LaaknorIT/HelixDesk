<?php

namespace App\Services\ActiveDirectory;

use App\Models\OrganizationIntegration;
use Illuminate\Support\Str;

/**
 * API credentials for on-premises collectors. Each integration has one key; only its SHA-256 hash is stored,
 * so the key is shown once when it is created and can only be replaced, never read back.
 */
final class CollectorKeyService
{
    public const PREFIX = 'hdc_';

    public function issue(OrganizationIntegration $integration): string
    {
        $key = self::PREFIX.Str::random(48);

        $integration->credentials = [
            ...$integration->mergedCredentials(),
            'collector_key_hash' => hash('sha256', $key),
            'collector_key_hint' => substr($key, -4),
            'collector_key_created_at' => now()->toIso8601String(),
        ];
        $integration->save();

        activity()
            ->performedOn($integration)
            ->event('collector_key_issued')
            ->log('Collector key issued');

        return $key;
    }

    public function matches(OrganizationIntegration $integration, ?string $presentedKey): bool
    {
        $hash = (string) ($integration->mergedCredentials()['collector_key_hash'] ?? '');

        return $hash !== '' && $presentedKey !== null && $presentedKey !== ''
            && hash_equals($hash, hash('sha256', $presentedKey));
    }

    public function hasKey(OrganizationIntegration $integration): bool
    {
        return (string) ($integration->mergedCredentials()['collector_key_hash'] ?? '') !== '';
    }

    public function hint(OrganizationIntegration $integration): ?string
    {
        $hint = (string) ($integration->mergedCredentials()['collector_key_hint'] ?? '');

        return $hint !== '' ? '…'.$hint : null;
    }
}
