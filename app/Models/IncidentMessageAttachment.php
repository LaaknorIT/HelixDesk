<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncidentMessageAttachment extends TenantModel
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'incident_message_id',
        'filename',
        'mime_type',
        'size',
        'storage_path',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(IncidentMessage::class, 'incident_message_id');
    }
}
