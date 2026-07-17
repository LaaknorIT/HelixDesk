<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SlaPolicy extends TenantModel
{
    protected $fillable = [
        'tenant_id',
        'name',
        'is_active',
        'target_type',
        'first_response_minutes',
        'resolution_minutes',
        'calendar_type',
        'applies_to_priority',
        'applies_to_organization_id',
        'applies_to_category_id',
        'applies_to_work_item_type',
        'match_priority',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'first_response_minutes' => 'integer',
            'resolution_minutes' => 'integer',
            'match_priority' => 'integer',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'applies_to_organization_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(WorkItemCategory::class, 'applies_to_category_id');
    }

    public function workItems(): HasMany
    {
        return $this->hasMany(WorkItem::class, 'sla_policy_id');
    }
}
