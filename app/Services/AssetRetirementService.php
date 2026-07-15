<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\AssetExternalIdentifier;
use App\Models\Tenant;
use App\Support\TenantIntegrationSettings;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity;

final class AssetRetirementService
{
    private const LOG_NAME = 'assets';

    private const EVENT_RETIRED = 'retired';

    private const EVENT_RETIREMENT_SUGGESTED = 'retirement_suggested';

    public function shouldAutoRetire(Tenant $tenant): bool
    {
        return TenantIntegrationSettings::autoRetireEnabled($tenant);
    }

    public function retirementGraceDays(Tenant $tenant): int
    {
        return TenantIntegrationSettings::retirementGraceDays($tenant);
    }

    public function evaluateAsset(Asset $asset, ?Carbon $now = null): bool
    {
        $tenant = Tenant::query()->find($asset->tenant_id);
        if ($tenant === null) {
            return false;
        }

        if ($asset->lifecycle_status === 'retired') {
            return false;
        }

        $graceDays = $this->retirementGraceDays($tenant);
        if (! $this->allExternalIdentifiersStale($asset, $graceDays, $now)) {
            return false;
        }

        if ($this->shouldAutoRetire($tenant)) {
            return $this->retireAsset($asset, $graceDays);
        }

        return $this->suggestRetirement($asset, $graceDays);
    }

    /**
     * @return int Number of assets retired or flagged for agent review.
     */
    public function evaluateTenant(Tenant $tenant, ?Carbon $now = null): int
    {
        $now ??= now();
        $retire = $this->shouldAutoRetire($tenant);

        return $this->processStaleAssets($tenant, $now, $retire);
    }

    public function allExternalIdentifiersStale(Asset $asset, int $graceDays, ?Carbon $now = null): bool
    {
        $now ??= now();
        $cutoff = $now->copy()->subDays($graceDays);

        $identifiers = AssetExternalIdentifier::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $asset->tenant_id)
            ->where('asset_id', $asset->getKey())
            ->get();

        if ($identifiers->isEmpty()) {
            return false;
        }

        foreach ($identifiers as $identifier) {
            if ($identifier->last_seen_at === null || $identifier->last_seen_at->greaterThanOrEqualTo($cutoff)) {
                return false;
            }
        }

        return true;
    }

    private function processStaleAssets(Tenant $tenant, Carbon $now, bool $retire): int
    {
        $count = 0;
        $graceDays = $this->retirementGraceDays($tenant);

        Asset::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->getKey())
            ->where('lifecycle_status', '!=', 'retired')
            ->chunkById(100, function ($assets) use (&$count, $graceDays, $now, $retire): void {
                foreach ($assets as $asset) {
                    if (! $this->allExternalIdentifiersStale($asset, $graceDays, $now)) {
                        continue;
                    }

                    if ($retire) {
                        if ($this->retireAsset($asset, $graceDays)) {
                            $count++;
                        }
                    } elseif ($this->suggestRetirement($asset, $graceDays)) {
                        $count++;
                    }
                }
            });

        return $count;
    }

    private function retireAsset(Asset $asset, int $graceDays): bool
    {
        $previousStatus = $asset->lifecycle_status;
        $asset->lifecycle_status = 'retired';
        $asset->save();

        $this->logRetirement($asset, $previousStatus, $graceDays);

        return true;
    }

    private function suggestRetirement(Asset $asset, int $graceDays): bool
    {
        if ($this->hasOpenRetirementSuggestion($asset)) {
            return false;
        }

        activity(self::LOG_NAME)
            ->performedOn($asset)
            ->event(self::EVENT_RETIREMENT_SUGGESTED)
            ->withProperties([
                'grace_days' => $graceDays,
                'organization_id' => $asset->organization_id,
                'trigger' => 'integration_sync',
            ])
            ->log('Asset is no longer reported by any integration provider; review for retirement.');

        return true;
    }

    private function hasOpenRetirementSuggestion(Asset $asset): bool
    {
        return Activity::query()
            ->where('log_name', self::LOG_NAME)
            ->where('subject_type', $asset->getMorphClass())
            ->where('subject_id', $asset->getKey())
            ->where('event', self::EVENT_RETIREMENT_SUGGESTED)
            ->exists();
    }

    private function logRetirement(Asset $asset, ?string $previousStatus, int $graceDays): void
    {
        activity(self::LOG_NAME)
            ->performedOn($asset)
            ->event(self::EVENT_RETIRED)
            ->withProperties([
                'grace_days' => $graceDays,
                'organization_id' => $asset->organization_id,
                'trigger' => 'integration_sync',
                'attribute_changes' => [
                    'attributes' => ['lifecycle_status' => 'retired'],
                    'old' => ['lifecycle_status' => $previousStatus],
                ],
            ])
            ->log('Asset retired automatically after all integration providers stopped reporting the device.');
    }
}
