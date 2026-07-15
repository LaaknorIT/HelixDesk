<?php

namespace App\Support;

final class AssetIdentityNormalizer
{
    /**
     * @param  list<string>  $blocklist
     */
    public static function normalizeSerial(?string $serial, ?array $blocklist = null): ?string
    {
        if ($serial === null) {
            return null;
        }

        $normalized = trim($serial);
        if ($normalized === '') {
            return null;
        }

        $blocklist ??= config('helixdesk_integrations.identity.serial_blocklist', []);
        foreach ($blocklist as $blocked) {
            if (strcasecmp($normalized, (string) $blocked) === 0) {
                return null;
            }
        }

        return $normalized;
    }

    public static function normalizeHostname(?string $hostname): ?string
    {
        if ($hostname === null) {
            return null;
        }

        $normalized = strtolower(trim($hostname));
        if ($normalized === '') {
            return null;
        }

        $short = explode('.', $normalized)[0];

        return $short !== '' ? $short : null;
    }

    public static function normalizeMac(?string $mac): ?string
    {
        if ($mac === null) {
            return null;
        }

        $hex = strtolower(preg_replace('/[^a-fA-F0-9]/', '', $mac) ?? '');
        if (strlen($hex) !== 12) {
            return null;
        }

        return implode(':', str_split($hex, 2));
    }
}
