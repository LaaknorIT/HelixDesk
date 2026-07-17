<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('name');
            $table->boolean('is_enabled')->default(true);
            $table->boolean('is_system')->default(false);
            $table->string('trigger');
            $table->json('conditions_json')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'trigger', 'is_enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_rules');
    }
};
