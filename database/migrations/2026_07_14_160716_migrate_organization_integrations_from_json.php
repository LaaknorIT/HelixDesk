<?php

use App\Services\OrganizationIntegrationMigrator;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $migrator = app(OrganizationIntegrationMigrator::class);
        $migrator->migrateAll();
        $migrator->stripAllLegacyIntegrationJson();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Data migration is intentionally not reversed — use backups if rollback is required.
    }
};
