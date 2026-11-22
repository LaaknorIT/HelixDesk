<?php

namespace App\Livewire;

use App\Models\User;
use App\Services\WorkItems\WorkItemPresence;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Collision warning on the agent ticket page: sends a presence heartbeat and lists the other agents
 * who have the ticket open. See docs/agent-productivity.md.
 */
class WorkItemViewers extends Component
{
    #[Locked]
    public int $workItemId;

    /**
     * @var list<string>
     */
    public array $viewers = [];

    public function mount(int $workItemId): void
    {
        $this->workItemId = $workItemId;
        $this->heartbeat();
    }

    public function heartbeat(): void
    {
        $user = auth()->user();

        $this->viewers = $user instanceof User
            ? app(WorkItemPresence::class)->heartbeat($this->workItemId, $user)
            : [];
    }

    public function render(): View
    {
        return view('livewire.work-item-viewers', [
            'pollSeconds' => WorkItemPresence::HEARTBEAT_SECONDS,
        ]);
    }
}
