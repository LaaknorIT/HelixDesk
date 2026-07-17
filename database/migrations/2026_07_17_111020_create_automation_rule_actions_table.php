<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_rule_actions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->foreignId('automation_rule_id')->constrained('automation_rules')->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('action_type');
            $table->json('action_config_json')->nullable();
            $table->timestamps();

            $table->index(['automation_rule_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_rule_actions');
    }
};
