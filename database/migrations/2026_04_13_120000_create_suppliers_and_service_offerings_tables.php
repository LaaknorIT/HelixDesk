<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('suppliers')) {
            Schema::create('suppliers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->string('name');
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->unique(['tenant_id', 'name']);
                $table->index(['tenant_id', 'name']);
            });
        }

        if (! Schema::hasTable('service_offerings')) {
            Schema::create('service_offerings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
                $table->string('name');
                $table->text('description')->nullable();
                $table->string('code')->nullable();
                $table->string('kind');
                $table->json('attributes_json')->nullable();
                $table->timestamps();

                $table->index(['tenant_id', 'organization_id']);
                $table->index(['tenant_id', 'kind']);
            });
        }

        if (! Schema::hasTable('service_offering_supplier')) {
            Schema::create('service_offering_supplier', function (Blueprint $table) {
                $table->id();
                $table->foreignId('service_offering_id')->constrained('service_offerings')->cascadeOnDelete();
                $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
                $table->string('role');
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->unique(['service_offering_id', 'supplier_id', 'role'], 'so_supplier_offering_supplier_role_uq');
                $table->index(['supplier_id']);
            });
        }

        if (! Schema::hasTable('service_offering_asset')) {
            Schema::create('service_offering_asset', function (Blueprint $table) {
                $table->id();
                $table->foreignId('service_offering_id')->constrained('service_offerings')->cascadeOnDelete();
                $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
                $table->string('role');
                $table->timestamps();

                $table->unique(['service_offering_id', 'asset_id', 'role'], 'so_asset_offering_asset_role_uq');
                $table->index(['asset_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('service_offering_asset');
        Schema::dropIfExists('service_offering_supplier');
        Schema::dropIfExists('service_offerings');
        Schema::dropIfExists('suppliers');
    }
};
