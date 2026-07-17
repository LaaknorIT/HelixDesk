<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowTransition extends TenantModel
{
    protected $fillable = [
        'tenant_id',
        'workflow_definition_id',
        'from_status',
        'to_status',
        'required_permission',
    ];

    protected function casts(): array
    {
        return [];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function workflowDefinition(): BelongsTo
    {
        return $this->belongsTo(WorkflowDefinition::class, 'workflow_definition_id');
    }
}
