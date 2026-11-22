<?php

namespace App\Services;

use App\Enums\DeviceClass;
use App\Enums\DeviceSourceRole;
use App\Models\Asset;
use App\Models\AssetSourceRecord;
use App\Models\OrganizationIntegration;
use App\Support\AssetIdentityNormalizer;
use App\Support\DeviceCoverage\CoverageRule;
use App\Support\DeviceCoverage\CoverageRules;
use App\Support\DeviceCoverage\DeviceCoverageReport;
use App\Support\DeviceSourceCatalog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Compares what each device source reports, per device, and applies the coverage rules. Sources and rules come
 * from the registry (DeviceSourceCatalog, CoverageRules), so no integration is named here.
 */
final class DeviceCoverageService
{
    public const SUMMARY_CACHE_SECONDS = 600;

    /**
     * Missing-device counts per rule for one organization, cached for a few minutes.
     *
     * @return array<string, int> rule key => missing devices
     */
    public static function cachedMissingCounts(int $tenantId, int $organizationId): array
    {
        return Cache::remember(
            'device-coverage:missing:'.$tenantId.':'.$organizationId,
            self::SUMMARY_CACHE_SECONDS,
            function () use ($tenantId, $organizationId): array {
                $counts = [];
                foreach (app(self::class)->build($tenantId, $organizationId)->ruleSummaries as $summary) {
                    $counts[$summary['rule']->key] = $summary['missing'];
                }

                return $counts;
            },
        );
    }

    public function build(int $tenantId, ?int $organizationId = null): DeviceCoverageReport
    {
        $catalog = DeviceSourceCatalog::all();

        $integrations = OrganizationIntegration::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->when($organizationId !== null, fn ($query) => $query->where('organization_id', $organizationId))
            ->where('enabled', true)
            ->get();

        /** @var array<int, array<string, true>> $sourcesByOrganization */
        $sourcesByOrganization = [];
        /** @var array<string, int> $thresholdByIntegration */
        $thresholdByIntegration = [];

        foreach ($integrations as $integration) {
            foreach (DeviceSourceCatalog::enabledFor($integration) as $sourceKey) {
                $sourcesByOrganization[(int) $integration->organization_id][$sourceKey] = true;
            }

            $threshold = (int) ($integration->config['active_threshold_days'] ?? 0);
            $thresholdByIntegration[(string) $integration->getKey()] = $threshold > 0 ? $threshold : AssetSourceRecord::DEFAULT_ACTIVE_THRESHOLD_DAYS;
        }

        $columns = [];
        foreach ($catalog as $key => $source) {
            foreach ($sourcesByOrganization as $sources) {
                if (isset($sources[$key])) {
                    $columns[$key] = $source['label'];

                    break;
                }
            }
        }

        $records = AssetSourceRecord::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->when($organizationId !== null, fn ($query) => $query->where('organization_id', $organizationId))
            ->whereNull('gone_at')
            ->with(['asset' => fn ($query) => $query->withoutGlobalScopes(), 'organization' => fn ($query) => $query->withoutGlobalScopes()])
            ->get()
            ->filter(fn (AssetSourceRecord $record): bool => isset($sourcesByOrganization[(int) $record->organization_id][$record->source]));

        $groups = $this->groupByDevice($tenantId, $records);

        $rules = CoverageRules::builtIn();
        $summaries = [];
        foreach ($rules as $rule) {
            $summaries[$rule->key] = ['rule' => $rule, 'in_scope' => 0, 'missing' => 0, 'applies' => false];
        }

        $rows = [];
        foreach ($groups as $groupKey => $group) {
            $organizationSources = $sourcesByOrganization[$group['organization_id']] ?? [];
            $row = $this->buildRow($groupKey, $group, array_keys($columns), $organizationSources, $thresholdByIntegration, $catalog);

            foreach ($rules as $rule) {
                if (! $this->ruleApplies($rule, $organizationSources, $catalog)) {
                    continue;
                }

                $summaries[$rule->key]['applies'] = true;

                if (! $this->rowInScope($rule, $row, $group['records'], $thresholdByIntegration, $catalog)) {
                    continue;
                }

                $summaries[$rule->key]['in_scope']++;

                $expectedSources = array_filter(
                    array_keys($organizationSources),
                    fn (string $key): bool => $this->hasAnyRole($catalog[$key]['roles'] ?? [], $rule->expectIn),
                );

                $reported = array_filter($expectedSources, fn (string $key): bool => in_array($row['cells'][$key] ?? null, [
                    DeviceCoverageReport::CELL_ACTIVE,
                    DeviceCoverageReport::CELL_STALE,
                    DeviceCoverageReport::CELL_DISABLED,
                ], true));

                if ($reported !== []) {
                    continue;
                }

                $summaries[$rule->key]['missing']++;
                $row['findings'][] = $rule->key;
                foreach ($expectedSources as $key) {
                    $row['cells'][$key] = DeviceCoverageReport::CELL_MISSING;
                }
            }

            $rows[] = $row;
        }

        usort($rows, fn (array $a, array $b): int => [count($b['findings']) > 0, $a['organization'], mb_strtolower($a['name'])]
            <=> [count($a['findings']) > 0, $b['organization'], mb_strtolower($b['name'])]);

        $ruleSummaries = [];
        foreach ($summaries as $summary) {
            if ($summary['applies']) {
                unset($summary['applies']);
                $ruleSummaries[] = $summary;
            }
        }

        return new DeviceCoverageReport($columns, $ruleSummaries, $rows);
    }

    /**
     * Groups records into devices: by linked asset, otherwise by a single asset in the same organization with the
     * same short hostname (shown as a probable match), otherwise by hostname, otherwise one device per record.
     *
     * @param  Collection<int, AssetSourceRecord>  $records
     * @return array<string, array{organization_id: int, organization: string, asset: ?Asset, probable_match: bool, records: list<AssetSourceRecord>}>
     */
    private function groupByDevice(int $tenantId, Collection $records): array
    {
        $unlinkedHostnames = $records
            ->whereNull('asset_id')
            ->pluck('hostname')
            ->filter()
            ->unique()
            ->values();

        /** @var array<string, list<Asset>> $assetsByHostname organization_id|hostname => assets */
        $assetsByHostname = [];
        if ($unlinkedHostnames->isNotEmpty()) {
            Asset::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereIn('organization_id', $records->pluck('organization_id')->unique())
                ->where(function ($query) use ($unlinkedHostnames): void {
                    foreach ($unlinkedHostnames as $hostname) {
                        $query->orWhereRaw('LOWER(primary_hostname) = ?', [$hostname])
                            ->orWhereRaw('LOWER(primary_hostname) LIKE ?', [$hostname.'.%']);
                    }
                })
                ->get()
                ->each(function (Asset $asset) use (&$assetsByHostname): void {
                    $hostname = AssetIdentityNormalizer::normalizeHostname($asset->primary_hostname);
                    if ($hostname !== null) {
                        $assetsByHostname[$asset->organization_id.'|'.$hostname][] = $asset;
                    }
                });
        }

        $groups = [];
        foreach ($records as $record) {
            $asset = $record->asset;
            $probable = false;

            if ($asset === null && $record->hostname !== null) {
                $candidates = $assetsByHostname[$record->organization_id.'|'.$record->hostname] ?? [];
                if (count($candidates) === 1) {
                    $asset = $candidates[0];
                    $probable = true;
                }
            }

            $groupKey = match (true) {
                $asset !== null => 'asset:'.$asset->getKey(),
                $record->hostname !== null => 'host:'.$record->organization_id.':'.$record->hostname,
                default => 'record:'.$record->getKey(),
            };

            $groups[$groupKey] ??= [
                'organization_id' => (int) $record->organization_id,
                'organization' => (string) ($record->organization?->name ?? ''),
                'asset' => $asset,
                'probable_match' => false,
                'records' => [],
            ];
            $groups[$groupKey]['records'][] = $record;
            $groups[$groupKey]['probable_match'] = $groups[$groupKey]['probable_match'] || $probable;
        }

        return $groups;
    }

    /**
     * @param  array{organization_id: int, organization: string, asset: ?Asset, probable_match: bool, records: list<AssetSourceRecord>}  $group
     * @param  list<string>  $columns
     * @param  array<string, true>  $organizationSources
     * @param  array<string, int>  $thresholdByIntegration
     * @param  array<string, array<string, mixed>>  $catalog
     * @return array<string, mixed>
     */
    private function buildRow(string $groupKey, array $group, array $columns, array $organizationSources, array $thresholdByIntegration, array $catalog): array
    {
        $rank = [
            DeviceCoverageReport::CELL_ACTIVE => 3,
            DeviceCoverageReport::CELL_STALE => 2,
            DeviceCoverageReport::CELL_DISABLED => 1,
        ];

        $cells = [];
        $lastActivity = [];
        foreach ($columns as $key) {
            $cells[$key] = isset($organizationSources[$key]) ? DeviceCoverageReport::CELL_ABSENT : DeviceCoverageReport::CELL_NOT_ENABLED;
            $lastActivity[$key] = null;
        }

        $deviceClass = null;
        $operatingSystem = $group['asset']?->operating_system;
        $name = $group['asset']?->name;

        foreach ($group['records'] as $record) {
            $status = $this->cellStatus($record, $thresholdByIntegration);
            $current = $cells[$record->source] ?? DeviceCoverageReport::CELL_ABSENT;
            if (($rank[$status] ?? 0) > ($rank[$current] ?? 0)) {
                $cells[$record->source] = $status;
            }

            if ($record->last_activity_at !== null
                && ($lastActivity[$record->source] === null || $record->last_activity_at->toIso8601String() > $lastActivity[$record->source])) {
                $lastActivity[$record->source] = $record->last_activity_at->toIso8601String();
            }

            if (($deviceClass === null || $deviceClass === DeviceClass::Other) && $record->device_class !== null) {
                $deviceClass = $record->device_class;
            }

            $operatingSystem ??= $record->operating_system;
            $name ??= $record->display_name ?? $record->hostname;
        }

        return [
            'key' => $groupKey,
            'organization_id' => $group['organization_id'],
            'organization' => $group['organization'],
            'asset_id' => $group['asset']?->getKey(),
            'name' => (string) ($name ?? __('Unknown device')),
            'device_class' => ($deviceClass ?? DeviceClass::fromOperatingSystem($operatingSystem))->value,
            'operating_system' => $operatingSystem,
            'probable_match' => $group['probable_match'],
            'cells' => $cells,
            'last_activity' => $lastActivity,
            'findings' => [],
        ];
    }

    /**
     * @param  array<string, int>  $thresholdByIntegration
     */
    private function cellStatus(AssetSourceRecord $record, array $thresholdByIntegration): string
    {
        $threshold = $thresholdByIntegration[(string) $record->organization_integration_id] ?? null;

        return match ($record->status($threshold)) {
            AssetSourceRecord::STATUS_ACTIVE => DeviceCoverageReport::CELL_ACTIVE,
            AssetSourceRecord::STATUS_DISABLED => DeviceCoverageReport::CELL_DISABLED,
            default => DeviceCoverageReport::CELL_STALE,
        };
    }

    /**
     * @param  array<string, true>  $organizationSources
     * @param  array<string, array<string, mixed>>  $catalog
     */
    private function ruleApplies(CoverageRule $rule, array $organizationSources, array $catalog): bool
    {
        $hasFrom = false;
        $hasExpected = false;

        foreach (array_keys($organizationSources) as $key) {
            $roles = $catalog[$key]['roles'] ?? [];
            $hasFrom = $hasFrom || $this->hasAnyRole($roles, $rule->whenActiveIn);
            $hasExpected = $hasExpected || $this->hasAnyRole($roles, $rule->expectIn);
        }

        return $hasFrom && $hasExpected;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<AssetSourceRecord>  $records
     * @param  array<string, int>  $thresholdByIntegration
     * @param  array<string, array<string, mixed>>  $catalog
     */
    private function rowInScope(CoverageRule $rule, array $row, array $records, array $thresholdByIntegration, array $catalog): bool
    {
        if (! in_array(DeviceClass::from($row['device_class']), $rule->deviceClasses, true)
            || ! $rule->appliesToOperatingSystem($row['operating_system'])) {
            return false;
        }

        foreach ($records as $record) {
            $roles = $catalog[$record->source]['roles'] ?? [];
            if ($this->hasAnyRole($roles, $rule->whenActiveIn)
                && $this->cellStatus($record, $thresholdByIntegration) === DeviceCoverageReport::CELL_ACTIVE) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<DeviceSourceRole>  $roles
     * @param  list<DeviceSourceRole>  $wanted
     */
    private function hasAnyRole(array $roles, array $wanted): bool
    {
        foreach ($roles as $role) {
            if (in_array($role, $wanted, true)) {
                return true;
            }
        }

        return false;
    }
}
