<?php

namespace App\Models;

use App\Enums\WorkItemSource;
use App\Enums\WorkItemType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class WorkItem extends TenantModel
{
    protected $fillable = [
        'tenant_id',
        'organization_id',
        'reported_by_user_id',
        'assigned_agent_id',
        'queue_id',
        'type',
        'title',
        'description',
        'priority',
        'category_id',
        'status',
        'source',
        'mail_account_id',
        'inbound_message_id',
        'opened_at',
        'resolved_at',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => WorkItemType::class,
            'source' => WorkItemSource::class,
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
        return $this->hasMany(WorkItemMessage::class, 'work_item_id');
    }

    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by_user_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function mailAccount(): BelongsTo
    {
        return $this->belongsTo(MailAccount::class);
    }

    public function queue(): BelongsTo
    {
        return $this->belongsTo(Queue::class);
    }

    public function problems(): BelongsToMany
    {
        return $this->belongsToMany(Problem::class, 'work_item_problem');
    }

    public function assets(): BelongsToMany
    {
        return $this->belongsToMany(Asset::class, 'asset_work_item');
    }

    public function monitoringEvents(): BelongsToMany
    {
        return $this->belongsToMany(MonitoringEvent::class, 'monitoring_event_work_item');
    }

    public function documents(): MorphToMany
    {
        return $this->morphToMany(Document::class, 'linkable', 'document_links')
            ->using(DocumentLink::class)
            ->withTimestamps();
    }
}
