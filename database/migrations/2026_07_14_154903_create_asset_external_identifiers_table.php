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
        Schema::create('asset_external_identifiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('organization_integration_id')->constrained('organization_integrations')->cascadeOnDelete();
            $table->string('provider');
            $table->string('identifier_type');
            $table->string('external_id', 191);
            $table->boolean('is_primary')->default(false);
            $table->timestamp('last_seen_at')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->unique(
                ['tenant_id', 'provider', 'identifier_type', 'external_id'],
                'asset_ext_ids_tenant_provider_type_ext_unique'
            );
            $table->unique(
                ['tenant_id', 'asset_id', 'provider', 'identifier_type', 'external_id'],
                'asset_ext_ids_asset_provider_type_ext_unique'
            );
            $table->index(['tenant_id', 'asset_id', 'provider'], 'asset_ext_ids_asset_provider_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('asset_external_identifiers');
    }
};
