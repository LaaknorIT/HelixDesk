<?php

namespace App\Enums;

enum AssetExternalIdentifierType: string
{
    case EntraAzureAdDeviceId = 'entra_azure_ad_device_id';
    case EntraIntuneManagedDeviceId = 'entra_intune_managed_device_id';
    case EntraDeviceId = 'entra_device_id';
    case Action1EndpointId = 'action1_endpoint_id';
    case AdObjectGuid = 'ad_object_guid';
    case AdObjectSid = 'ad_object_sid';
    case DefenderMachineId = 'defender_machine_id';
    case SerialNumber = 'serial_number';
    case Hostname = 'hostname';
    case MacAddress = 'mac_address';

    public function isHardMatchType(): bool
    {
        return match ($this) {
            self::EntraAzureAdDeviceId,
            self::EntraIntuneManagedDeviceId,
            self::EntraDeviceId,
            self::Action1EndpointId,
            self::AdObjectGuid,
            self::AdObjectSid,
            self::DefenderMachineId => true,
            default => false,
        };
    }

    public function isSoftMatchHint(): bool
    {
        return match ($this) {
            self::SerialNumber,
            self::Hostname,
            self::MacAddress => true,
            default => false,
        };
    }
}
