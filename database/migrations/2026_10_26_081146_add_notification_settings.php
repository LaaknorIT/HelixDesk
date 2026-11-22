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
        Schema::table('users', function (Blueprint $table): void {
            $table->json('notification_preferences')->nullable();
        });

        Schema::table('queues', function (Blueprint $table): void {
            $table->text('teams_webhook_url')->nullable();
            $table->text('slack_webhook_url')->nullable();
            $table->json('chat_notification_events')->nullable();
        });

        Schema::table('work_items', function (Blueprint $table): void {
            $table->timestamp('response_warning_notified_at')->nullable();
            $table->timestamp('resolution_warning_notified_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('work_items', function (Blueprint $table): void {
            $table->dropColumn(['response_warning_notified_at', 'resolution_warning_notified_at']);
        });

        Schema::table('queues', function (Blueprint $table): void {
            $table->dropColumn(['teams_webhook_url', 'slack_webhook_url', 'chat_notification_events']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('notification_preferences');
        });
    }
};
