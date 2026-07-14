<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('work_item_messages', function (Blueprint $table) {
            $table->unsignedBigInteger('parent_message_id')->nullable()->after('work_item_id');
            $table->boolean('is_internal')->default(false)->after('author_user_id');

            $table->index(['work_item_id', 'is_internal'], 'work_item_messages_incident_internal_index');
            $table->index(['work_item_id', 'parent_message_id'], 'work_item_messages_thread_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('work_item_messages', function (Blueprint $table) {
            $table->dropIndex('work_item_messages_incident_internal_index');
            $table->dropIndex('work_item_messages_thread_index');
            $table->dropColumn('parent_message_id');
            $table->dropColumn('is_internal');
        });
    }
};
