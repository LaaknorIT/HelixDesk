<?php

namespace App\Models;

use App\Enums\WorkItemSource;
use App\Enums\WorkItemType;
use App\Services\Mail\WorkItemPublicIdGenerator;
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
        'sla_policy_id',
        'status',
        'source',
        'mail_account_id',
        'inbound_message_id',
        'public_id',
        'opened_at',
        'first_response_due_at',
        'resolution_due_at',
        'first_responded_at',
        'sla_paused_at',
        'sla_paused_seconds',
        'response_breached_at',
        'resolution_breached_at',
        'resolved_at',
        'closed_at',
        'response_warning_notified_at',
        'resolution_warning_notified_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => WorkItemType::class,
            'source' => WorkItemSource::class,
            'opened_at' => 'datetime',
            'first_response_due_at' => 'datetime',
            'resolution_due_at' => 'datetime',
            'first_responded_at' => 'datetime',
            'sla_paused_at' => 'datetime',
            'response_breached_at' => 'datetime',
            'resolution_breached_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
            'sla_paused_seconds' => 'integer',
            'response_warning_notified_at' => 'datetime',
            'resolution_warning_notified_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        parent::booted();

        static::creating(function (WorkItem $workItem): void {
            if (is_string($workItem->public_id) && trim($workItem->public_id) !== '') {
                return;
            }

            $tenantId = (int) ($workItem->tenant_id ?? 0);
            if ($tenantId <= 0) {
                return;
            }

            $workItem->public_id = app(WorkItemPublicIdGenerator::class)->generate($tenantId);
        });
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

    public function assignedAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_agent_id');
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

    public function category(): BelongsTo
    {
        return $this->belongsTo(WorkItemCategory::class, 'category_id');
    }

    public function slaPolicy(): BelongsTo
    {
        return $this->belongsTo(SlaPolicy::class, 'sla_policy_id');
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
