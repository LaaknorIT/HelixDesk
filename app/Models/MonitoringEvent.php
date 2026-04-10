<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class MonitoringEvent extends TenantModel
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'monitoring_event_source_id',
        'external_reference',
        'severity',
        'message',
        'status',
        'deduplication_key',
        'occurrence_count',
        'first_seen_at',
        'last_seen_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(MonitoringEventSource::class, 'monitoring_event_source_id');
    }

    public function incidents(): BelongsToMany
    {
        return $this->belongsToMany(Incident::class, 'monitoring_event_incident');
    }
}
