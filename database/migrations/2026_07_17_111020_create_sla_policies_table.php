<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sla_policies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->string('target_type'); // first_response | resolution | both
            $table->unsignedInteger('first_response_minutes')->nullable();
            $table->unsignedInteger('resolution_minutes')->nullable();
            $table->string('calendar_type')->default('working_hours'); // working_hours | continuous
            $table->string('applies_to_priority')->nullable();
            $table->unsignedBigInteger('applies_to_organization_id')->nullable();
            $table->unsignedBigInteger('applies_to_category_id')->nullable();
            $table->string('applies_to_work_item_type')->nullable();
            $table->integer('match_priority')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
            $table->unique(['tenant_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sla_policies');
    }
};
