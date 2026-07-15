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
        Schema::create('asset_patch_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('asset_patch_status_id')->constrained('asset_patch_status')->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->foreignUuid('organization_integration_id')->constrained('organization_integrations')->cascadeOnDelete();
            $table->string('provider');
            $table->string('kb_id');
            $table->string('cve_id')->nullable();
            $table->string('title');
            $table->string('severity')->default('other');
            $table->string('approval_status')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->unique(
                ['tenant_id', 'asset_id', 'provider', 'organization_integration_id', 'kb_id'],
                'asset_patch_items_unique'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('asset_patch_items');
    }
};
