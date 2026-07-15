<?php

namespace App\Support;

final class AssetIdentifierHelper
{
    public static function isGuid(?string $value): bool
    {
        if ($value === null) {
            return false;
        }

        $trimmed = trim($value);

        return $trimmed !== ''
            && (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $trimmed);
    }

    public static function looksLikeHostname(?string $value): bool
    {
        if ($value === null) {
            return false;
        }

        $trimmed = trim($value);
        if ($trimmed === '' || self::isGuid($trimmed)) {
            return false;
        }

        return (bool) preg_match('/^[a-z0-9]([a-z0-9.-]{0,253}[a-z0-9])?$/i', $trimmed);
    }

    public static function generatedAssetTag(int $assetId): string
    {
        return 'HD-'.$assetId;
    }
}
