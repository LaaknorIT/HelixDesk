<?php

namespace App\Filament\Agent\Widgets;

use App\Enums\AssetIdentityAmbiguityStatus;
use App\Enums\AssetPatchComplianceStatus;
use App\Models\AssetIdentityAmbiguity;
use App\Models\AssetPatchStatus;
use App\Models\OrganizationIntegration;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class IntegrationSyncHealthWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    protected function getStats(): array
    {
        $tenant = Filament::getTenant();
        if ($tenant === null) {
            return [];
        }

        $integrations = OrganizationIntegration::query()
            ->where('tenant_id', $tenant->getKey())
            ->get();

        $syncErrors = $integrations->filter(fn (OrganizationIntegration $integration): bool => filled($integration->last_sync_error))->count();
        $lastSync = $integrations->max('last_sync_at');

        $openAmbiguities = AssetIdentityAmbiguity::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('status', AssetIdentityAmbiguityStatus::Open)
            ->count();

        $nonCompliantPatches = AssetPatchStatus::query()
            ->where('tenant_id', $tenant->getKey())
            ->whereIn('compliance_status', [
                AssetPatchComplianceStatus::UpdatesMissing,
                AssetPatchComplianceStatus::CriticalMissing,
            ])
            ->count();

        return [
            Stat::make('Integrations with sync errors', (string) $syncErrors)
                ->description($syncErrors === 0 ? 'All integrations healthy' : 'Review organization integration settings')
                ->color($syncErrors === 0 ? 'success' : 'danger'),
            Stat::make('Last integration sync', $lastSync?->diffForHumans() ?? 'Never')
                ->description('Most recent successful sync across integrations'),
            Stat::make('Open identity ambiguities', (string) $openAmbiguities)
                ->description('Assets awaiting manual identity resolution')
                ->color($openAmbiguities === 0 ? 'success' : 'warning'),
            Stat::make('Non-compliant patch assets', (string) $nonCompliantPatches)
                ->description('Assets missing critical or other updates'),
        ];
    }
}
