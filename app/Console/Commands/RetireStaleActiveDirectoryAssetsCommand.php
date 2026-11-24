<?php

namespace App\Console\Commands;

use App\Models\OrganizationIntegration;
use App\Services\ActiveDirectory\StaleActiveDirectoryAssetCleanup;
use App\Support\IntegrationType;
use Illuminate\Console\Command;

class RetireStaleActiveDirectoryAssetsCommand extends Command
{
    protected $signature = 'helixdesk:retire-stale-ad-assets
        {--tenant= : Only this tenant (ID)}
        {--integration= : Only this Active Directory integration (ID)}
        {--dry-run : List the assets without retiring them}';

    protected $description = 'Retire assets that only Active Directory reported and that AD now reports as disabled, inactive or deleted';

    public function handle(StaleActiveDirectoryAssetCleanup $cleanup): int
    {
        $integrations = OrganizationIntegration::query()
            ->withoutGlobalScopes()
            ->where('type', IntegrationType::ACTIVE_DIRECTORY)
            ->when($this->option('tenant') !== null, fn ($query) => $query->where('tenant_id', (int) $this->option('tenant')))
            ->when($this->option('integration') !== null, fn ($query) => $query->whereKey((string) $this->option('integration')))
            ->orderBy('tenant_id')
            ->get();

        $dryRun = (bool) $this->option('dry-run');
        $total = 0;

        foreach ($integrations as $integration) {
            $candidates = $cleanup->candidates($integration);
            if ($candidates->isEmpty()) {
                continue;
            }

            $domain = (string) ($integration->mergedConfig()['domain_fqdn'] ?? $integration->getKey());
            foreach ($candidates as $asset) {
                $this->line(sprintf('%s tenant %d, %s: #%d "%s"', $dryRun ? '[dry run]' : 'Retiring', $integration->tenant_id, $domain, $asset->getKey(), $asset->name));
            }

            $total += $dryRun ? $candidates->count() : $cleanup->retire($integration);
        }

        $this->info($dryRun
            ? "Found {$total} stale Active Directory asset(s). Run without --dry-run to retire them."
            : "Retired {$total} stale Active Directory asset(s).");

        return self::SUCCESS;
    }
}
