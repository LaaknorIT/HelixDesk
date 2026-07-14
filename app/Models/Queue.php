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
        'default_mail_account_id',
    ];

    public function defaultMailAccount(): BelongsTo
    {
        return $this->belongsTo(MailAccount::class, 'default_mail_account_id');
    }

    public function mailAccounts(): BelongsToMany
    {
        return $this->belongsToMany(MailAccount::class, 'queue_mail_account');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function workItems(): HasMany
    {
        return $this->hasMany(WorkItem::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(TenantRole::class, 'role_queue', 'queue_id', 'role_id')
            ->withPivot('work_item_permissions')
            ->withTimestamps();
    }
}
