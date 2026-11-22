<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CannedResponse extends TenantModel
{
    protected $fillable = [
        'tenant_id',
        'queue_id',
        'title',
        'body',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function queue(): BelongsTo
    {
        return $this->belongsTo(Queue::class);
    }

    /**
     * Responses offered on a ticket: shared ones plus those for the ticket's queue.
     *
     * @param  Builder<CannedResponse>  $query
     * @return Builder<CannedResponse>
     */
    public function scopeAvailableFor(Builder $query, WorkItem $workItem): Builder
    {
        return $query
            ->where('tenant_id', $workItem->tenant_id)
            ->where(function (Builder $query) use ($workItem): void {
                $query->whereNull('queue_id');

                if ($workItem->queue_id !== null) {
                    $query->orWhere('queue_id', $workItem->queue_id);
                }
            });
    }
}
