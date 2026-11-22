<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_integrations', function (Blueprint $table): void {
            $table->timestamp('last_sync_failed_at')->nullable()->after('last_sync_error');
            $table->json('health_metadata')->nullable()->after('last_sync_failed_at');
        });
    }

    public function down(): void
    {
        Schema::table('organization_integrations', function (Blueprint $table): void {
            $table->dropColumn(['last_sync_failed_at', 'health_metadata']);
        });
    }
};
