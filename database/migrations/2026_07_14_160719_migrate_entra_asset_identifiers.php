<?php

use App\Services\EntraAssetIdentifierMigrator;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        app(EntraAssetIdentifierMigrator::class)->migrateAll();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Data migration is intentionally not reversed — use backups if rollback is required.
    }
};
