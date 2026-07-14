<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkItemMessageAttachment extends TenantModel
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'work_item_message_id',
        'filename',
        'mime_type',
        'size',
        'storage_path',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(WorkItemMessage::class, 'work_item_message_id');
    }
}
