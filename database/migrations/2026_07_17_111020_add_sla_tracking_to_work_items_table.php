<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_items', function (Blueprint $table) {
            $table->unsignedBigInteger('sla_policy_id')->nullable()->after('category_id');
            $table->timestamp('first_response_due_at')->nullable()->after('opened_at');
            $table->timestamp('resolution_due_at')->nullable()->after('first_response_due_at');
            $table->timestamp('first_responded_at')->nullable()->after('resolution_due_at');
            $table->timestamp('sla_paused_at')->nullable()->after('first_responded_at');
            $table->unsignedInteger('sla_paused_seconds')->default(0)->after('sla_paused_at');
            $table->timestamp('response_breached_at')->nullable()->after('sla_paused_seconds');
            $table->timestamp('resolution_breached_at')->nullable()->after('response_breached_at');

            $table->index(['tenant_id', 'sla_policy_id']);
            $table->index(['tenant_id', 'first_response_due_at']);
            $table->index(['tenant_id', 'resolution_due_at']);
        });
    }

    public function down(): void
    {
        Schema::table('work_items', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'sla_policy_id']);
            $table->dropIndex(['tenant_id', 'first_response_due_at']);
            $table->dropIndex(['tenant_id', 'resolution_due_at']);
            $table->dropColumn([
                'sla_policy_id',
                'first_response_due_at',
                'resolution_due_at',
                'first_responded_at',
                'sla_paused_at',
                'sla_paused_seconds',
                'response_breached_at',
                'resolution_breached_at',
            ]);
        });
    }
};
