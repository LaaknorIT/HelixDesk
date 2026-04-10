<?php

namespace App\Models;

use Database\Factories\QueueFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Queue extends TenantModel
{
    /** @use HasFactory<QueueFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'name',
        'description',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(Incident::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(TenantRole::class, 'role_queue', 'queue_id', 'role_id')
            ->withPivot('incident_permissions')
            ->withTimestamps();
    }
}
