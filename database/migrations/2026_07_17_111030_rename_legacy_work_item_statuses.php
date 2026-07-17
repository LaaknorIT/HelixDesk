<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $renames = [
            'In Progress' => 'Working',
            'Waiting for User' => 'Waiting for customer',
            'Resolved' => 'Closed (pending)',
        ];

        foreach ($renames as $from => $to) {
            DB::table('work_items')->where('status', $from)->update(['status' => $to]);
            DB::table('workflow_transitions')->where('from_status', $from)->update(['from_status' => $to]);
            DB::table('workflow_transitions')->where('to_status', $from)->update(['to_status' => $to]);
        }
    }

    public function down(): void
    {
        $renames = [
            'Working' => 'In Progress',
            'Waiting for customer' => 'Waiting for User',
            'Closed (pending)' => 'Resolved',
        ];

        foreach ($renames as $from => $to) {
            DB::table('work_items')->where('status', $from)->update(['status' => $to]);
            DB::table('workflow_transitions')->where('from_status', $from)->update(['from_status' => $to]);
            DB::table('workflow_transitions')->where('to_status', $from)->update(['to_status' => $to]);
        }
    }
};
