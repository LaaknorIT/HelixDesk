<?php

namespace App\Enums;

enum WorkItemNotificationEvent: string
{
    case Created = 'created';
    case Assigned = 'assigned';
    case CustomerReplied = 'customer_replied';
    case SlaAtRisk = 'sla_at_risk';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'New ticket',
            self::Assigned => 'Ticket assigned to me',
            self::CustomerReplied => 'Customer reply',
            self::SlaAtRisk => 'SLA close to breach',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    /**
     * Events posted to a queue's chat channels when the queue has not chosen its own.
     *
     * @return list<string>
     */
    public static function defaultChatEvents(): array
    {
        return [self::Created->value, self::CustomerReplied->value, self::SlaAtRisk->value];
    }
}
