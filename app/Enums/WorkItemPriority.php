<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Ticket priority. Stored as the lowercase value on `work_items.priority` and `sla_policies.applies_to_priority`.
 */
enum WorkItemPriority: string implements HasColor, HasLabel
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Urgent = 'urgent';

    public function getLabel(): string
    {
        return match ($this) {
            self::Low => __('Low'),
            self::Normal => __('Normal'),
            self::High => __('High'),
            self::Urgent => __('Urgent'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Low => 'gray',
            self::Normal => 'info',
            self::High => 'warning',
            self::Urgent => 'danger',
        };
    }

    /**
     * Read a priority from any of the names HelixDesk and its integrations have used
     * ("Medium", "Critical", "HIGH" ...). Unknown or empty values give null.
     */
    public static function fromName(mixed $value): ?self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return match (strtolower(trim($value))) {
            'low', 'lowest', 'minor' => self::Low,
            'normal', 'medium', 'moderate', 'default' => self::Normal,
            'high', 'major' => self::High,
            'urgent', 'critical', 'highest', 'blocker' => self::Urgent,
            default => null,
        };
    }

    /**
     * The stored value for any priority name; unknown names fall back to normal.
     */
    public static function normalize(mixed $value): string
    {
        return (self::fromName($value) ?? self::Normal)->value;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->getLabel();
        }

        return $options;
    }

    public static function labelFor(mixed $value): string
    {
        $priority = self::fromName($value);

        if ($priority !== null) {
            return $priority->getLabel();
        }

        return is_string($value) && $value !== '' ? __(ucfirst($value)) : '—';
    }

    public static function colorFor(mixed $value): string
    {
        return self::fromName($value)?->getColor() ?? 'gray';
    }
}
