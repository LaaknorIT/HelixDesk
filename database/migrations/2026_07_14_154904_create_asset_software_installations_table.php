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
        Schema::create('asset_software_installations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->foreignUuid('organization_integration_id')->constrained('organization_integrations')->cascadeOnDelete();
            $table->string('provider');
            $table->string('external_id')->nullable();
            $table->string('name');
            $table->string('version')->nullable();
            $table->string('publisher')->nullable();
            $table->date('install_date')->nullable();
            $table->string('source')->default('discovered');
            $table->timestamp('last_seen_at')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->unique(
                ['tenant_id', 'asset_id', 'provider', 'external_id'],
                'asset_software_tenant_asset_provider_ext_unique'
            );
            $table->index(['tenant_id', 'asset_id', 'provider'], 'asset_software_asset_provider_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('asset_software_installations');
    }
};
