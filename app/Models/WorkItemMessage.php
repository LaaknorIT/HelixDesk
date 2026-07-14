<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkItemMessage extends TenantModel
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'work_item_id',
        'author_user_id',
        'parent_message_id',
        'is_internal',
        'body',
        'external_message_id',
        'in_reply_to',
        'email_references',
        'from_address',
        'to_addresses',
        'mail_account_id',
        'email_sent_at',
    ];

    public function workItem(): BelongsTo
    {
        return $this->belongsTo(WorkItem::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_message_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_message_id');
    }

    public function mailAccount(): BelongsTo
    {
        return $this->belongsTo(MailAccount::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(WorkItemMessageAttachment::class);
    }

    protected function casts(): array
    {
        return [
            'is_internal' => 'boolean',
            'to_addresses' => 'array',
            'email_sent_at' => 'datetime',
        ];
    }
}
