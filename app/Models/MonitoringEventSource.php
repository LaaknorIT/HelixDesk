<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MonitoringEventSource extends TenantModel
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'slug',
        'name',
        'provider',
        'configuration_json',
    ];

    protected function casts(): array
    {
        return [
            'configuration_json' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function monitoringEvents(): HasMany
    {
        return $this->hasMany(MonitoringEvent::class, 'monitoring_event_source_id');
    }
}
