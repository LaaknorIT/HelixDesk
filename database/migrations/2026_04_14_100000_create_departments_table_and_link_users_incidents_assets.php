<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('parent_department_id')->nullable()->constrained('departments')->cascadeOnDelete();
            $table->string('name');
            $table->string('code')->nullable();
            $table->string('external_reference')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'organization_id']);
            $table->index(['organization_id', 'parent_department_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable()->after('organization_id')->constrained('departments')->nullOnDelete();
        });

        Schema::table('incidents', function (Blueprint $table) {
            $table->foreign('department_id')->references('id')->on('departments')->nullOnDelete();
        });

        Schema::table('assets', function (Blueprint $table) {
            $table->foreign('department_id')->references('id')->on('departments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropForeign(['department_id']);
        });

        Schema::table('incidents', function (Blueprint $table) {
            $table->dropForeign(['department_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['department_id']);
            $table->dropColumn('department_id');
        });

        Schema::dropIfExists('departments');
    }
};
