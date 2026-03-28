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
        Schema::create('workflow_transitions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('workflow_definition_id');

            $table->string('from_status');
            $table->string('to_status');
            $table->string('required_permission')->nullable();

            $table->timestamps();

            $table->index(
                ['tenant_id', 'workflow_definition_id', 'from_status', 'to_status'],
                'workflow_transitions_lookup_index'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workflow_transitions');
    }
};
