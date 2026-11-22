<?php

namespace App\Support;

use App\Models\WorkItemStatus;
use Filament\Support\Colors\Color;
use Throwable;

/**
 * How ticket statuses look: a translated name and a badge colour.
 *
 * Status names are tenant data (an admin can add their own), so they go through the translator and fall back
 * to the name as typed. A colour set on the status wins; otherwise the colour follows what the status does.
 */
class WorkItemStatusDisplay
{
    public static function label(?string $status): string
    {
        return filled($status) ? __((string) $status) : '—';
    }

    /**
     * @return string|array<int|string, string>
     */
    public static function color(?string $status): string|array
    {
        if (blank($status)) {
            return 'gray';
        }

        $catalog = once(fn (): array => WorkItemStatus::query()
            ->get(['name', 'color', 'pauses_sla', 'sets_resolved_at', 'sets_closed_at', 'is_default_open'])
            ->keyBy('name')
            ->all());

        /** @var WorkItemStatus|null $definition */
        $definition = $catalog[$status] ?? null;

        if ($definition !== null && filled($definition->color)) {
            try {
                return Color::hex((string) $definition->color);
            } catch (Throwable) {
                // Not a colour Filament can read; fall through to the default.
            }
        }

        return match (true) {
            $definition?->sets_closed_at === true, $definition?->sets_resolved_at === true => 'success',
            $definition?->pauses_sla === true => 'gray',
            $definition?->is_default_open === true => 'info',
            default => 'warning',
        };
    }

    /**
     * @return array<string, string> Status name => translated label, in catalog order.
     */
    public static function options(): array
    {
        return WorkItemStatus::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->pluck('name')
            ->mapWithKeys(fn (string $name): array => [$name => __($name)])
            ->all();
    }
}
