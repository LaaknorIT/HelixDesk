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
        Schema::create('asset_patch_status', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->foreignUuid('organization_integration_id')->constrained('organization_integrations')->cascadeOnDelete();
            $table->string('provider');
            $table->unsignedInteger('missing_critical_count')->default(0);
            $table->unsignedInteger('missing_other_count')->default(0);
            $table->timestamp('last_assessed_at')->nullable();
            $table->string('compliance_status')->default('unknown');
            $table->timestamps();

            $table->unique(
                ['tenant_id', 'asset_id', 'provider', 'organization_integration_id'],
                'asset_patch_status_unique'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('asset_patch_status');
    }
};
