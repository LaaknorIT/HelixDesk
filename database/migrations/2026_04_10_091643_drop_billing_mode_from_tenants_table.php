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
        if (! Schema::hasColumn('tenants', 'billing_mode')) {
            return;
        }

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('billing_mode');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('tenants', 'billing_mode')) {
            return;
        }

        Schema::table('tenants', function (Blueprint $table) {
            $table->string('billing_mode')->default('none')->after('status');
        });
    }
};
