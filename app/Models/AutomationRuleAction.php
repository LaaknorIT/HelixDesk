<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomationRuleAction extends TenantModel
{
    protected $fillable = [
        'tenant_id',
        'automation_rule_id',
        'sort_order',
        'action_type',
        'action_config_json',
    ];

    protected function casts(): array
    {
        return [
            'action_config_json' => 'array',
            'sort_order' => 'integer',
        ];
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AutomationRule::class, 'automation_rule_id');
    }
}
