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
        Schema::table('assets', function (Blueprint $table) {
            $table->string('primary_hostname')->nullable()->after('serial_number');
            $table->string('operating_system')->nullable()->after('primary_hostname');
            $table->string('identity_resolution_status')->default('manual')->after('lifecycle_status');
            $table->timestamp('last_inventory_sync_at')->nullable()->after('identity_resolution_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn([
                'primary_hostname',
                'operating_system',
                'identity_resolution_status',
                'last_inventory_sync_at',
            ]);
        });
    }
};
