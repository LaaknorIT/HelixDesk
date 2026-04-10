<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'status',
        'region',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function tenantRoles(): HasMany
    {
        return $this->hasMany(TenantRole::class, 'team_id');
    }

    public function organizations(): HasMany
    {
        return $this->hasMany(Organization::class);
    }

    public function queues(): HasMany
    {
        return $this->hasMany(Queue::class);
    }

    public function generalQueue(): ?Queue
    {
        return $this->queues()
            ->where('name', 'General')
            ->first();
    }

    public function assetTypes(): HasMany
    {
        return $this->hasMany(AssetType::class);
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }

    public function monitoringEventSources(): HasMany
    {
        return $this->hasMany(MonitoringEventSource::class);
    }

    public function monitoringEvents(): HasMany
    {
        return $this->hasMany(MonitoringEvent::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function dashboardLayouts(): HasMany
    {
        return $this->hasMany(DashboardLayout::class);
    }

    public function monitoringQueue(): ?Queue
    {
        return $this->queues()
            ->where('name', 'Monitoring')
            ->first();
    }
}
