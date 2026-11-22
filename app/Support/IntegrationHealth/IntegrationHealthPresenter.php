<?php

namespace App\Support\IntegrationHealth;

use App\Enums\IntegrationHealthStatus;
use Filament\Schemas\Components\Callout;

/**
 * Renders health reports as Filament components, so every screen explains problems the same way.
 */
final class IntegrationHealthPresenter
{
    /**
     * @return list<Callout>
     */
    public static function callouts(IntegrationHealthReport $report): array
    {
        if ($report->status === IntegrationHealthStatus::Disabled) {
            return [
                Callout::make(__('This integration is disabled'))
                    ->description(__('Enable it in the last step of the setup to start syncing.'))
                    ->icon(IntegrationHealthStatus::Disabled->icon())
                    ->color('gray'),
            ];
        }

        if ($report->issues === []) {
            return [
                Callout::make(__('Everything looks good'))
                    ->description($report->integration->last_sync_at !== null
                        ? __('Last successful sync :ago.', ['ago' => $report->integration->last_sync_at->diffForHumans()])
                        : __('No problems found.'))
                    ->success(),
            ];
        }

        $callouts = [];
        foreach ([...$report->errors(), ...$report->warnings()] as $issue) {
            $description = $issue->detail;
            if ($issue->occurredAt !== null && in_array($issue->code, ['sync_failed', 'connection_check_failed'], true)) {
                $description .= ' '.__('(:ago)', ['ago' => $issue->occurredAt->diffForHumans()]);
            }

            $callout = Callout::make($issue->title)
                ->description($description)
                ->icon($issue->severity->icon());

            $callouts[] = $issue->isError() ? $callout->danger() : $callout->warning();
        }

        return $callouts;
    }

    public static function shortDescription(IntegrationHealthReport $report): ?string
    {
        $issue = $report->firstIssue();
        if ($issue === null) {
            return null;
        }

        $others = count($report->issues) - 1;

        return $others > 0
            ? __(':title (+:count more)', ['title' => $issue->title, 'count' => $others])
            : $issue->title;
    }
}
