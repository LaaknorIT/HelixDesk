<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkflowDefinition extends TenantModel
{
    protected $fillable = [
        'tenant_id',
        'entity_type',
        'name',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    public function transitions(): HasMany
    {
        return $this->hasMany(WorkflowTransition::class, 'workflow_definition_id');
    }
}
