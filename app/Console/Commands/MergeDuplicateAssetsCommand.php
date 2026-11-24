<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\AssetMergeService;
use Illuminate\Console\Command;

class MergeDuplicateAssetsCommand extends Command
{
    protected $signature = 'helixdesk:merge-duplicate-assets
        {--tenant= : Only this tenant (ID)}
        {--dry-run : List the duplicates without merging them}';

    protected $description = 'Merge synced assets that are the same device (same hostname in the same organization, no conflicting serial number or device ID)';

    public function handle(AssetMergeService $mergeService): int
    {
        $tenantIds = $this->option('tenant') !== null
            ? [(int) $this->option('tenant')]
            : Tenant::query()->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();

        $dryRun = (bool) $this->option('dry-run');
        $total = 0;

        foreach ($tenantIds as $tenantId) {
            foreach ($mergeService->findDuplicateGroups($tenantId) as $group) {
                $keep = $group[0];
                $duplicates = array_slice($group, 1);
                $total += count($duplicates);

                $this->line(sprintf(
                    '%s tenant %d: keep #%d "%s", merge %s',
                    $dryRun ? '[dry run]' : 'Merging',
                    $tenantId,
                    $keep->getKey(),
                    $keep->name,
                    implode(', ', array_map(fn ($asset): string => '#'.$asset->getKey(), $duplicates)),
                ));

                if (! $dryRun) {
                    foreach ($duplicates as $duplicate) {
                        if ($mergeService->mergeBlocker($keep, $duplicate) === null) {
                            $keep = $mergeService->merge($keep, $duplicate);
                        }
                    }
                }
            }
        }

        $this->info($dryRun
            ? "Found {$total} duplicate asset(s). Run without --dry-run to merge them."
            : "Merged {$total} duplicate asset(s).");

        return self::SUCCESS;
    }
}
