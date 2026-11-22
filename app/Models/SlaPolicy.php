<?php

namespace App\Models;

use App\Enums\WorkItemPriority;
use Illuminate\Database\Eloquent\Casts\Attribute;
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

    /**
     * Stored with the same names as ticket priorities, so the policy matches; empty means any priority.
     */
    protected function appliesToPriority(): Attribute
    {
        return Attribute::make(
            set: fn (mixed $value): ?string => blank($value) ? null : WorkItemPriority::normalize($value),
        );
    }

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
