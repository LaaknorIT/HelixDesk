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
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('type')->default('customer');
            $table->string('name');
            $table->string('code')->nullable();
            $table->boolean('is_billable')->default(false);
            $table->unsignedInteger('order')->default(0);
            $table->bigInteger('parent_id')->default(-1);
            $table->string('external_reference')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'parent_id']);
            $table->index(['tenant_id', 'type']);
        });

        Schema::create('queues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index('tenant_id');
        });

        Schema::create('problems', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->text('root_cause')->nullable();
            $table->text('workaround')->nullable();
            $table->text('permanent_fix')->nullable();
            $table->string('status')->default('open');
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        Schema::create('work_item_problem', function (Blueprint $table) {
            $table->foreignId('work_item_id')->constrained('work_items')->cascadeOnDelete();
            $table->foreignId('problem_id')->constrained('problems')->cascadeOnDelete();
            $table->primary(['work_item_id', 'problem_id']);
        });

        Schema::create('changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('risk_id')->nullable()->index();
            $table->string('status')->default('Draft');
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('implementation_plan')->nullable();
            $table->text('rollback_plan')->nullable();
            $table->timestamp('scheduled_start_at')->nullable();
            $table->timestamp('scheduled_end_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('organization_id')->nullable()->after('tenant_id')->constrained('organizations')->nullOnDelete();
        });

        Schema::table('work_items', function (Blueprint $table) {
            $table->foreign('organization_id')->references('id')->on('organizations')->nullOnDelete();
            $table->foreign('queue_id')->references('id')->on('queues')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('work_items', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropForeign(['queue_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropColumn('organization_id');
        });

        Schema::dropIfExists('changes');
        Schema::dropIfExists('work_item_problem');
        Schema::dropIfExists('problems');
        Schema::dropIfExists('queues');
        Schema::dropIfExists('organizations');
    }
};
