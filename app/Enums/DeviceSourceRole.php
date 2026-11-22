<?php

namespace App\Enums;

/**
 * What it means for a device to be present in a device source. Coverage rules are written against roles,
 * so any integration that registers a source with a role is checked without naming the product.
 */
enum DeviceSourceRole: string
{
    case Directory = 'directory';
    case DeviceManagement = 'device_management';
    case RemoteManagement = 'remote_management';
    case EndpointSecurity = 'endpoint_security';

    public function label(): string
    {
        return match ($this) {
            self::Directory => __('Directory'),
            self::DeviceManagement => __('Device management (MDM)'),
            self::RemoteManagement => __('Remote management (RMM)'),
            self::EndpointSecurity => __('Endpoint security'),
        };
    }
}
