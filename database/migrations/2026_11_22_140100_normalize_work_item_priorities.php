<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tickets used two sets of priority names (Low/Medium/High/Critical and normal/high/urgent), so SLA policies
 * did not match tickets from the portal or monitoring. Store one set: low, normal, high, urgent.
 */
return new class extends Migration
{
    /**
     * @var array<string, string>
     */
    private const MAP = [
        'low' => 'low',
        'lowest' => 'low',
        'minor' => 'low',
        'normal' => 'normal',
        'medium' => 'normal',
        'moderate' => 'normal',
        'high' => 'high',
        'major' => 'high',
        'urgent' => 'urgent',
        'critical' => 'urgent',
        'highest' => 'urgent',
    ];

    public function up(): void
    {
        foreach (self::MAP as $from => $to) {
            DB::table('work_items')->whereRaw('LOWER(priority) = ?', [$from])->update(['priority' => $to]);
            DB::table('sla_policies')->whereRaw('LOWER(applies_to_priority) = ?', [$from])->update(['applies_to_priority' => $to]);
        }
    }

    public function down(): void
    {
        // The old names cannot be told apart again; the normalized values stay.
    }
};
