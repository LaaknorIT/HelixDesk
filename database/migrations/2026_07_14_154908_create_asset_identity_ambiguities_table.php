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
        Schema::create('asset_identity_ambiguities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('organization_integration_id')->constrained('organization_integrations')->cascadeOnDelete();
            $table->string('provider');
            $table->json('candidate_asset_ids');
            $table->json('snapshot_json');
            $table->string('status')->default('open');
            $table->foreignId('resolved_asset_id')->nullable()->constrained('assets')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'organization_id', 'status'], 'asset_identity_ambiguities_review_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('asset_identity_ambiguities');
    }
};
