<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AutomationRule extends TenantModel
{
    protected $fillable = [
        'tenant_id',
        'name',
        'is_enabled',
        'is_system',
        'trigger',
        'conditions_json',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'is_system' => 'boolean',
            'conditions_json' => 'array',
            'sort_order' => 'integer',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function actions(): HasMany
    {
        return $this->hasMany(AutomationRuleAction::class)->orderBy('sort_order');
    }
}
