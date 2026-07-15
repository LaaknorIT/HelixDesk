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
        Schema::create('organization_integrations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('type');
            $table->boolean('enabled')->default(false);
            $table->json('config')->nullable();
            $table->text('credentials')->nullable();
            $table->string('api_base_url')->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->text('last_sync_error')->nullable();
            $table->unsignedInteger('sync_interval_hours')->nullable();
            $table->string('scope_key')->default('');
            $table->timestamps();

            $table->index(['tenant_id', 'organization_id', 'type'], 'org_integrations_tenant_org_type_idx');
            $table->index(['tenant_id', 'enabled', 'last_sync_at'], 'org_integrations_scheduler_idx');
            $table->unique(
                ['tenant_id', 'organization_id', 'type', 'scope_key'],
                'org_integrations_tenant_org_type_scope_unique'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organization_integrations');
    }
};
