<?php

namespace App\Services\WorkItems;

use App\Models\User;
use App\Models\WorkItem;
use Illuminate\Support\Str;

/**
 * Fills the placeholders of a canned response for one ticket. See docs/agent-productivity.md.
 */
class CannedResponseRenderer
{
    public function render(string $body, WorkItem $workItem, ?User $agent): string
    {
        $workItem->loadMissing(['reportedBy', 'queue']);

        $requesterName = trim((string) $workItem->reportedBy?->name);
        $values = [
            'requester.name' => $requesterName,
            'requester.first_name' => $requesterName === '' ? '' : (string) Str::of($requesterName)->explode(' ')->first(),
            'ticket.id' => '#'.$workItem->getKey(),
            'ticket.title' => (string) $workItem->title,
            'agent.name' => (string) $agent?->name,
            'queue.name' => (string) $workItem->queue?->name,
        ];

        return (string) preg_replace_callback(
            '/\{\{\s*([a-z_]+\.[a-z_]+)\s*\}\}/',
            fn (array $match): string => array_key_exists($match[1], $values) ? $values[$match[1]] : $match[0],
            $body,
        );
    }
}
