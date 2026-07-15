<?php

namespace App\Services;

use App\Enums\AssetExternalIdentifierType;
use App\Models\AssetExternalIdentifier;
use App\Models\MonitoringEvent;
use App\Support\IntegrationType;

final class MonitoringEventAssetLinker
{
    public function linkByAction1EndpointId(MonitoringEvent $event, ?string $endpointId): void
    {
        $endpointId = trim((string) $endpointId);
        if ($endpointId === '') {
            return;
        }

        $assetId = AssetExternalIdentifier::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $event->tenant_id)
            ->where('provider', IntegrationType::ACTION1)
            ->where('identifier_type', AssetExternalIdentifierType::Action1EndpointId)
            ->where('external_id', $endpointId)
            ->value('asset_id');

        if ($assetId === null) {
            return;
        }

        $event->assets()->syncWithoutDetaching([(int) $assetId]);
    }

    public function linkByEntraAzureAdDeviceId(MonitoringEvent $event, ?string $deviceId): void
    {
        $deviceId = trim((string) $deviceId);
        if ($deviceId === '') {
            return;
        }

        $assetId = AssetExternalIdentifier::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $event->tenant_id)
            ->where('identifier_type', AssetExternalIdentifierType::EntraAzureAdDeviceId)
            ->where('external_id', $deviceId)
            ->value('asset_id');

        if ($assetId === null) {
            return;
        }

        $event->assets()->syncWithoutDetaching([(int) $assetId]);
    }

    /**
     * @param  array<string, mixed>  $workItem
     */
    public function linkEntraSecurityWorkItem(MonitoringEvent $event, array $workItem): void
    {
        foreach ($this->extractEntraAzureAdDeviceIds($workItem) as $deviceId) {
            $this->linkByEntraAzureAdDeviceId($event, $deviceId);
        }
    }

    /**
     * @param  array<string, mixed>  $workItem
     * @return list<string>
     */
    public function extractEntraAzureAdDeviceIds(array $workItem): array
    {
        $ids = [];

        $scalarCandidates = [
            data_get($workItem, 'azureADDeviceId'),
            data_get($workItem, 'deviceState.azureADDeviceId'),
            data_get($workItem, 'affectedDevice.azureADDeviceId'),
            data_get($workItem, 'deviceDetails.azureADDeviceId'),
            data_get($workItem, 'device.azureADDeviceId'),
            data_get($workItem, 'device.deviceId'),
        ];

        foreach ($scalarCandidates as $candidate) {
            $normalized = $this->normalizeGuid((string) $candidate);
            if ($normalized !== null) {
                $ids[] = $normalized;
            }
        }

        foreach (data_get($workItem, 'devices', []) as $device) {
            if (! is_array($device)) {
                continue;
            }

            foreach ([
                $device['azureADDeviceId'] ?? null,
                $device['deviceId'] ?? null,
            ] as $candidate) {
                $normalized = $this->normalizeGuid((string) $candidate);
                if ($normalized !== null) {
                    $ids[] = $normalized;
                }
            }
        }

        foreach (data_get($workItem, 'impactedAssets', []) as $asset) {
            if (! is_array($asset)) {
                continue;
            }

            $normalized = $this->normalizeGuid((string) ($asset['azureADDeviceId'] ?? $asset['deviceId'] ?? ''));
            if ($normalized !== null) {
                $ids[] = $normalized;
            }
        }

        return array_values(array_unique($ids));
    }

    private function normalizeGuid(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value)) {
            return null;
        }

        return strtolower($value);
    }
}
