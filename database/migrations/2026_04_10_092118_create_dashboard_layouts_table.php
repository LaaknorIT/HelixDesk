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
        Schema::create('dashboard_layouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('scope', 32);
            $table->string('role_name')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->json('layout_json');
            $table->json('locked_widgets')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'scope', 'is_active'], 'dashboard_layouts_tenant_scope_active_idx');
            $table->unique(['tenant_id', 'scope', 'role_name'], 'dashboard_layouts_tenant_scope_role_unique');
            $table->unique(['tenant_id', 'scope', 'user_id'], 'dashboard_layouts_tenant_scope_user_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dashboard_layouts');
    }
};
