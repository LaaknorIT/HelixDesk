<?php

namespace App\Filament\Agent\Widgets;

use App\Enums\AssetIdentityAmbiguityStatus;
use App\Enums\AssetPatchComplianceStatus;
use App\Enums\IntegrationHealthStatus;
use App\Filament\Agent\Pages\Integrations;
use App\Models\AssetIdentityAmbiguity;
use App\Models\AssetPatchStatus;
use App\Models\OrganizationIntegration;
use App\Support\IntegrationHealth\IntegrationHealthInspector;
use App\Support\IntegrationHealth\IntegrationHealthReport;
use App\Support\IntegrationType;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Collection;

class IntegrationSyncHealthWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    protected function getHeading(): ?string
    {
        return __('Integrations and assets');
    }

    protected function getStats(): array
    {
        $tenant = Filament::getTenant();
        if ($tenant === null) {
            return [];
        }

        $integrations = OrganizationIntegration::query()
            ->where('tenant_id', $tenant->getKey())
            ->with('organization')
            ->get();

        $reports = IntegrationHealthInspector::inspectMany($integrations);
        $failing = $reports->filter(fn (IntegrationHealthReport $report): bool => $report->status === IntegrationHealthStatus::Error)->values();
        $needingAttention = $reports->filter(fn (IntegrationHealthReport $report): bool => $report->status === IntegrationHealthStatus::Warning)->values();
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
            Stat::make(__('Integrations with sync errors'), (string) $failing->count())
                ->description($failing->isEmpty() ? __('All integrations are syncing') : $this->describeReports($failing))
                ->descriptionIcon($failing->isEmpty() ? Heroicon::OutlinedCheckCircle : Heroicon::OutlinedArrowRight)
                ->color($failing->isEmpty() ? 'success' : 'danger')
                ->url($this->integrationsUrl($failing->isEmpty() ? null : IntegrationHealthStatus::Error)),
            Stat::make(__('Integration warnings'), (string) $needingAttention->count())
                ->description($needingAttention->isEmpty() ? __('No expiring secrets or missing permissions') : $this->describeReports($needingAttention))
                ->descriptionIcon($needingAttention->isEmpty() ? Heroicon::OutlinedCheckCircle : Heroicon::OutlinedArrowRight)
                ->color($needingAttention->isEmpty() ? 'success' : 'warning')
                ->url($this->integrationsUrl($needingAttention->isEmpty() ? null : IntegrationHealthStatus::Warning)),
            Stat::make(__('Last integration sync'), $lastSync?->diffForHumans() ?? __('Never'))
                ->description(__('Most recent successful sync across integrations'))
                ->url($this->integrationsUrl(null)),
            Stat::make(__('Open identity ambiguities'), (string) $openAmbiguities)
                ->description(__('Assets awaiting manual identity resolution'))
                ->color($openAmbiguities === 0 ? 'success' : 'warning'),
            Stat::make(__('Non-compliant patch assets'), (string) $nonCompliantPatches)
                ->description(__('Assets missing critical or other updates')),
        ];
    }

    /**
     * Names the affected integrations, e.g. "Action1 · Acme: Last sync failed".
     *
     * @param  Collection<int, IntegrationHealthReport>  $reports
     */
    private function describeReports(Collection $reports): string
    {
        $first = $reports->first();
        $integration = $first->integration;
        $summary = __(':integration · :organization: :issue', [
            'integration' => IntegrationType::label((string) $integration->type),
            'organization' => $integration->organization_id === null
                ? __('All organizations (root level)')
                : (string) ($integration->organization?->name ?? '—'),
            'issue' => $first->firstIssue()?->title ?? '',
        ]);

        $others = $reports->count() - 1;

        return $others > 0
            ? __(':summary (+:count more)', ['summary' => $summary, 'count' => $others])
            : $summary;
    }

    private function integrationsUrl(?IntegrationHealthStatus $status): ?string
    {
        if (! Integrations::canAccess()) {
            return null;
        }

        return Integrations::getUrl($status !== null ? ['filters' => ['status' => ['value' => $status->value]]] : []);
    }
}
