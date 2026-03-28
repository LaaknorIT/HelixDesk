<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Incident extends TenantModel
{
    protected $fillable = [
        'tenant_id',
        'organization_id',
        'department_id',
        'reported_by_user_id',
        'assigned_agent_id',
        'queue_id',
        'title',
        'description',
        'priority',
        'category_id',
        'status',
        'opened_at',
        'resolved_at',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(IncidentMessage::class, 'incident_id');
    }

    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by_user_id');
    }
}
