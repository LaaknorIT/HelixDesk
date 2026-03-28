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
        Schema::table('incident_messages', function (Blueprint $table) {
            $table->unsignedBigInteger('parent_message_id')->nullable()->after('incident_id');
            $table->boolean('is_internal')->default(false)->after('author_user_id');

            $table->index(['incident_id', 'is_internal'], 'incident_messages_incident_internal_index');
            $table->index(['incident_id', 'parent_message_id'], 'incident_messages_thread_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('incident_messages', function (Blueprint $table) {
            $table->dropIndex('incident_messages_incident_internal_index');
            $table->dropIndex('incident_messages_thread_index');
            $table->dropColumn('parent_message_id');
            $table->dropColumn('is_internal');
        });
    }
};
