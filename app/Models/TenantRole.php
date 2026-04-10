<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Tenant-scoped Spatie role with queue assignments for incident routing/visibility.
 */
class TenantRole extends SpatieRole
{
    /**
     * Filament panel tenancy uses relationship name `tenant` (see AgentPanelProvider); Spatie stores the tenant id in `team_id`.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'team_id');
    }

    public function queues(): BelongsToMany
    {
        return $this->belongsToMany(Queue::class, 'role_queue', 'role_id', 'queue_id')
            ->withPivot('incident_permissions')
            ->withTimestamps();
    }
}
