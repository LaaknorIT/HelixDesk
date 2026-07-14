<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Legacy placeholder: departments were folded into the organization tree
 * (`organizations.type = department`) before production. See
 * `2026_04_10_081449_add_organizations_queues_problems_and_changes_tables.php`.
 */
return new class extends Migration
{
    public function up(): void
    {
        //
    }

    public function down(): void
    {
        //
    }
};
