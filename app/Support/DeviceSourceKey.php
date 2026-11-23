<?php

namespace App\Support;

/**
 * Stable keys for device sources, stored in asset_source_records.source. An integration can produce several
 * sources (the Microsoft Entra integration produces Intune and Defender, and Entra ID devices later).
 */
final class DeviceSourceKey
{
    public const MICROSOFT_INTUNE = 'microsoft_intune';

    public const MICROSOFT_DEFENDER = 'microsoft_defender';

    public const ACTION1 = 'action1';

    public const ACTIVE_DIRECTORY = 'active_directory';
}
