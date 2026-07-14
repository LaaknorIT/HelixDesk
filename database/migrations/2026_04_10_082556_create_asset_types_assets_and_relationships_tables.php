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
        Schema::create('asset_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->foreignId('asset_type_id')->constrained('asset_types')->cascadeOnDelete();
            $table->string('name');
            $table->string('asset_identifier')->nullable();
            $table->string('serial_number')->nullable();
            $table->date('purchase_date')->nullable();
            $table->date('warranty_date')->nullable();
            $table->string('lifecycle_status')->default('in_use');
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'asset_type_id', 'lifecycle_status'], 'assets_tenant_type_lifecycle_idx');
        });

        Schema::create('asset_relationships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('parent_asset_id')->constrained('assets')->cascadeOnDelete();
            $table->foreignId('child_asset_id')->constrained('assets')->cascadeOnDelete();
            $table->string('relationship_type');
            $table->timestamps();

            $table->index(['tenant_id', 'parent_asset_id']);
            $table->index(['tenant_id', 'child_asset_id']);
        });

        Schema::create('asset_work_item', function (Blueprint $table) {
            $table->foreignId('work_item_id')->constrained('work_items')->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->primary(['work_item_id', 'asset_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('asset_work_item');
        Schema::dropIfExists('asset_relationships');
        Schema::dropIfExists('assets');
        Schema::dropIfExists('asset_types');
    }
};
