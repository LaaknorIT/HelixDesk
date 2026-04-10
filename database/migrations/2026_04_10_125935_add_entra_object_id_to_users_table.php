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
        Schema::table('users', function (Blueprint $table) {
            $table->string('entra_object_id')->nullable()->after('department_id');
            $table->index(['tenant_id', 'entra_object_id'], 'users_tenant_entra_object_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_tenant_entra_object_idx');
            $table->dropColumn('entra_object_id');
        });
    }
};
