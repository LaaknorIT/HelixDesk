<?php

namespace App\Console\Commands;

use App\Models\AssetSourceRecord;
use App\Models\Tenant;
use App\Services\MicrosoftEntra\DefenderDiscoveredDevices;
use App\Support\DeviceSourceKey;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class PruneDefenderDiscoveredDevicesCommand extends Command
{
    protected $signature = 'helixdesk:prune-defender-discovered-devices
        {--tenant= : Only this tenant (ID)}
        {--dry-run : List the devices without deleting them}';

    protected $description = 'Delete Microsoft Defender devices that are not onboarded and do not match a device HelixDesk knows (often shown with only an ID as name)';

    public function handle(): int
    {
        $tenantIds = $this->option('tenant') !== null
            ? [(int) $this->option('tenant')]
            : Tenant::query()->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();

        $dryRun = (bool) $this->option('dry-run');
        $total = 0;

        foreach ($tenantIds as $tenantId) {
            $knownHostnames = DefenderDiscoveredDevices::knownHostnames($tenantId);
            $toDelete = [];

            AssetSourceRecord::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('source', DeviceSourceKey::MICROSOFT_DEFENDER)
                ->whereNull('asset_id')
                ->chunkById(500, function (Collection $records) use ($knownHostnames, &$toDelete, $dryRun, $tenantId): void {
                    foreach ($records as $record) {
                        /** @var AssetSourceRecord $record */
                        if (! $record->isDiscoveredOnly()
                            || DefenderDiscoveredDevices::isKnown($record->display_name ?? $record->hostname, $knownHostnames)) {
                            continue;
                        }

                        $toDelete[] = (int) $record->getKey();
                        $this->line(sprintf(
                            '%s tenant %d: #%d "%s" (%s)',
                            $dryRun ? '[dry run]' : 'Deleting',
                            $tenantId,
                            $record->getKey(),
                            $record->display_name ?? $record->hostname ?? $record->provider_record_id,
                            $record->operating_system ?? 'unknown OS',
                        ));
                    }
                });

            $total += count($toDelete);

            if (! $dryRun) {
                foreach (array_chunk($toDelete, 500) as $ids) {
                    AssetSourceRecord::query()->withoutGlobalScopes()->whereKey($ids)->delete();
                }
            }
        }

        $this->info($dryRun
            ? "Found {$total} Defender device(s) that are not onboarded and not known. Run without --dry-run to delete them."
            : "Deleted {$total} Defender device(s) that are not onboarded and not known.");

        return self::SUCCESS;
    }
}
