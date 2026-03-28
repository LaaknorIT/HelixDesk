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
        Schema::create('incidents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');

            $table->unsignedBigInteger('organization_id')->nullable();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->unsignedBigInteger('reported_by_user_id')->nullable();
            $table->unsignedBigInteger('assigned_agent_id')->nullable();
            $table->unsignedBigInteger('queue_id')->nullable();

            $table->string('title');
            $table->text('description')->nullable();
            $table->string('priority');
            $table->unsignedBigInteger('category_id')->nullable();
            $table->string('status')->default('New');

            $table->timestamp('opened_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'status', 'queue_id', 'assigned_agent_id'], 'incidents_tenant_status_indexes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('incidents');
    }
};
