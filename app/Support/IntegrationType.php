<?php

namespace App\Support;

final class IntegrationType
{
    public const MICROSOFT_ENTRA = 'microsoft_entra';

    public const GOOGLE_WORKSPACE = 'google_workspace';

    public const ACTION1 = 'action1';

    /**
     * @return list<string>
     */
    public static function registered(): array
    {
        return [
            self::MICROSOFT_ENTRA,
            self::GOOGLE_WORKSPACE,
            self::ACTION1,
        ];
    }

    /**
     * @return list<string>
     */
    public static function mailTransportTypes(): array
    {
        return [
            self::MICROSOFT_ENTRA,
            self::GOOGLE_WORKSPACE,
        ];
    }

    public static function label(string $type): string
    {
        return match ($type) {
            self::MICROSOFT_ENTRA => __('Microsoft Entra ID'),
            self::GOOGLE_WORKSPACE => __('Google Workspace'),
            self::ACTION1 => __('Action1'),
            default => $type,
        };
    }
}
