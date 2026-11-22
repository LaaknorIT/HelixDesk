<?php

namespace App\Services\Notifications;

use App\Enums\WorkItemNotificationEvent;
use App\Filament\Agent\Resources\WorkItems\WorkItemResource;
use App\Models\WorkItem;
use Illuminate\Support\Str;

/**
 * The text and link shared by every channel for one work item notification.
 */
final readonly class WorkItemNotificationContent
{
    /**
     * @param  array<string, string>  $facts
     */
    public function __construct(
        public string $heading,
        public string $ticketLabel,
        public ?string $detail,
        public array $facts,
        public string $url,
    ) {}

    public static function for(WorkItem $workItem, WorkItemNotificationEvent $event, ?string $detail = null): self
    {
        $workItem->loadMissing(['queue', 'organization', 'assignedAgent', 'tenant']);

        $reference = filled($workItem->public_id) ? '#'.$workItem->public_id : '#'.$workItem->getKey();

        $facts = array_filter([
            'Queue' => $workItem->queue?->name,
            'Customer' => $workItem->organization?->name,
            'Priority' => filled($workItem->priority) ? Str::ucfirst((string) $workItem->priority) : null,
            'Status' => $workItem->status,
            'Assignee' => $workItem->assignedAgent?->name ?? 'Unassigned',
        ], fn (?string $value): bool => filled($value));

        return new self(
            heading: $event->label(),
            ticketLabel: $reference.' '.$workItem->title,
            detail: filled($detail) ? Str::limit(trim((string) $detail), 300) : null,
            facts: $facts,
            url: WorkItemResource::getUrl('view', ['record' => $workItem], panel: 'agent', tenant: $workItem->tenant),
        );
    }

    public function subject(): string
    {
        return $this->heading.': '.$this->ticketLabel;
    }
}
