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
        Schema::create('asset_source_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('organization_integration_id')->constrained('organization_integrations')->cascadeOnDelete();
            $table->string('source', 64);
            $table->string('provider_record_id', 191);
            $table->foreignId('asset_id')->nullable()->constrained('assets')->nullOnDelete();
            $table->string('display_name')->nullable();
            $table->string('hostname')->nullable();
            $table->string('fqdn')->nullable();
            $table->string('serial_number')->nullable();
            $table->json('mac_addresses')->nullable();
            $table->string('operating_system')->nullable();
            $table->string('device_class', 32)->nullable();
            $table->boolean('enabled')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_reported_at')->nullable();
            $table->timestamp('gone_at')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->unique(
                ['tenant_id', 'organization_integration_id', 'source', 'provider_record_id'],
                'asset_source_records_integration_record_unique'
            );
            $table->index(['tenant_id', 'organization_id', 'source'], 'asset_source_records_org_source_idx');
            $table->index(['tenant_id', 'asset_id'], 'asset_source_records_asset_idx');
            $table->index(['tenant_id', 'organization_id', 'hostname'], 'asset_source_records_org_hostname_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('asset_source_records');
    }
};
