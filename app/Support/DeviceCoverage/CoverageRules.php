<?php

namespace App\Support\DeviceCoverage;

use App\Enums\DeviceClass;
use App\Enums\DeviceSourceRole;

final class CoverageRules
{
    /**
     * Built-in rules. Each one is checked for an organization only when it has at least one enabled source on
     * each side of the rule.
     *
     * @return array<string, CoverageRule>
     */
    public static function builtIn(): array
    {
        $rules = [
            new CoverageRule(
                key: 'directory_to_device_management',
                name: __('Directory → device management'),
                likelyCause: __('Missing device management (e.g. Intune) license for the user, enrollment policy not applied, or enrollment failed.'),
                whenActiveIn: [DeviceSourceRole::Directory],
                expectIn: [DeviceSourceRole::DeviceManagement],
                deviceClasses: [DeviceClass::Workstation],
                operatingSystems: ['windows'],
            ),
            new CoverageRule(
                key: 'remote_management_to_device_management',
                name: __('Remote management → device management'),
                likelyCause: __('The device has an RMM agent but is not enrolled in device management, often because of a missing license.'),
                whenActiveIn: [DeviceSourceRole::RemoteManagement],
                expectIn: [DeviceSourceRole::DeviceManagement],
                deviceClasses: [DeviceClass::Workstation],
                operatingSystems: ['windows', 'mac'],
            ),
            new CoverageRule(
                key: 'device_management_to_remote_management',
                name: __('Device management → remote management'),
                likelyCause: __('The RMM agent is not deployed, for example missing from the app assignment in device management.'),
                whenActiveIn: [DeviceSourceRole::DeviceManagement],
                expectIn: [DeviceSourceRole::RemoteManagement],
                deviceClasses: [DeviceClass::Workstation],
                operatingSystems: ['windows', 'mac'],
            ),
            new CoverageRule(
                key: 'any_to_endpoint_security',
                name: __('Any source → endpoint security'),
                likelyCause: __('The endpoint security onboarding package is not deployed, or server protection is not enabled.'),
                whenActiveIn: [DeviceSourceRole::Directory, DeviceSourceRole::DeviceManagement, DeviceSourceRole::RemoteManagement],
                expectIn: [DeviceSourceRole::EndpointSecurity],
                deviceClasses: [DeviceClass::Workstation, DeviceClass::Server],
                operatingSystems: ['windows', 'mac', 'linux'],
            ),
        ];

        $keyed = [];
        foreach ($rules as $rule) {
            $keyed[$rule->key] = $rule;
        }

        return $keyed;
    }

    public static function find(string $key): ?CoverageRule
    {
        return self::builtIn()[$key] ?? null;
    }
}
