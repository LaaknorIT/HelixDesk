<?php

namespace App\Services\WorkItems;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Tracks which agents have a ticket open, for the collision warning. See docs/agent-productivity.md.
 */
class WorkItemPresence
{
    public const HEARTBEAT_SECONDS = 15;

    public const EXPIRES_AFTER_SECONDS = 45;

    /**
     * Record that the user is viewing the ticket and return the other agents viewing it.
     *
     * @return list<string> names of the other viewers
     */
    public function heartbeat(int $workItemId, User $user): array
    {
        $now = now()->getTimestamp();
        $viewers = $this->activeViewers($workItemId, $now);
        $viewers[(int) $user->getKey()] = ['name' => (string) $user->name, 'seen_at' => $now];

        Cache::put($this->key($workItemId), $viewers, self::EXPIRES_AFTER_SECONDS * 2);

        return $this->otherNames($viewers, (int) $user->getKey());
    }

    public function leave(int $workItemId, User $user): void
    {
        $viewers = $this->activeViewers($workItemId, now()->getTimestamp());
        unset($viewers[(int) $user->getKey()]);

        Cache::put($this->key($workItemId), $viewers, self::EXPIRES_AFTER_SECONDS * 2);
    }

    /**
     * @return list<string>
     */
    public function otherViewers(int $workItemId, User $user): array
    {
        return $this->otherNames($this->activeViewers($workItemId, now()->getTimestamp()), (int) $user->getKey());
    }

    /**
     * @return array<int, array{name: string, seen_at: int}>
     */
    private function activeViewers(int $workItemId, int $now): array
    {
        $viewers = Cache::get($this->key($workItemId), []);

        if (! is_array($viewers)) {
            return [];
        }

        return array_filter(
            $viewers,
            fn ($viewer): bool => is_array($viewer) && ($now - (int) ($viewer['seen_at'] ?? 0)) <= self::EXPIRES_AFTER_SECONDS,
        );
    }

    /**
     * @param  array<int, array{name: string, seen_at: int}>  $viewers
     * @return list<string>
     */
    private function otherNames(array $viewers, int $userId): array
    {
        unset($viewers[$userId]);

        $names = array_map(fn (array $viewer): string => $viewer['name'], $viewers);
        sort($names);

        return array_values($names);
    }

    private function key(int $workItemId): string
    {
        return 'work-item-viewers:'.$workItemId;
    }
}
