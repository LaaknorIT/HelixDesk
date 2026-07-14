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
        Schema::create('monitoring_event_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('slug');
            $table->string('name');
            $table->string('provider')->default('api');
            $table->json('configuration_json')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'slug']);
        });

        Schema::create('monitoring_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('monitoring_event_source_id')->constrained('monitoring_event_sources')->cascadeOnDelete();
            $table->string('external_reference')->nullable();
            $table->string('severity');
            $table->text('message');
            $table->string('status')->default('open');
            $table->string('deduplication_key');
            $table->unsignedInteger('occurrence_count')->default(1);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'deduplication_key', 'status'], 'monitoring_events_dedup_status_idx');
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('monitoring_event_work_item', function (Blueprint $table) {
            $table->foreignId('monitoring_event_id')->constrained('monitoring_events')->cascadeOnDelete();
            $table->foreignId('work_item_id')->constrained('work_items')->cascadeOnDelete();
            $table->primary(['monitoring_event_id', 'work_item_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('monitoring_event_work_item');
        Schema::dropIfExists('monitoring_events');
        Schema::dropIfExists('monitoring_event_sources');
    }
};
