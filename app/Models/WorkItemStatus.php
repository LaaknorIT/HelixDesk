<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkItemStatus extends TenantModel
{
    protected $fillable = [
        'tenant_id',
        'name',
        'sort_order',
        'is_system',
        'is_default_open',
        'pauses_sla',
        'sets_resolved_at',
        'sets_closed_at',
        'color',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'is_default_open' => 'boolean',
            'pauses_sla' => 'boolean',
            'sets_resolved_at' => 'boolean',
            'sets_closed_at' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function workItems(): HasMany
    {
        return $this->hasMany(WorkItem::class, 'status', 'name');
    }
}
