<?php

namespace App\Support;

/**
 * Stable keys for device sources, stored in asset_source_records.source. An integration can produce several
 * sources (the Microsoft Entra integration produces Entra ID devices, Intune and Defender).
 */
final class DeviceSourceKey
{
    public const MICROSOFT_INTUNE = 'microsoft_intune';

    public const MICROSOFT_ENTRA_DEVICE = 'microsoft_entra_device';

    public const MICROSOFT_DEFENDER = 'microsoft_defender';

    public const ACTION1 = 'action1';

    public const ACTIVE_DIRECTORY = 'active_directory';
}
