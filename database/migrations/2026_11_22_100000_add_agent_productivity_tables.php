<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('canned_responses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('queue_id')->nullable()->constrained('queues')->cascadeOnDelete();
            $table->string('title');
            $table->text('body');
            $table->timestamps();

            $table->index(['tenant_id', 'title']);
        });

        Schema::create('work_item_links', function (Blueprint $table): void {
            $table->foreignId('work_item_id')->constrained('work_items')->cascadeOnDelete();
            $table->foreignId('linked_work_item_id')->constrained('work_items')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->primary(['work_item_id', 'linked_work_item_id']);
            $table->index('linked_work_item_id');
        });

        Schema::create('work_item_watchers', function (Blueprint $table): void {
            $table->foreignId('work_item_id')->constrained('work_items')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['work_item_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::table('work_items', function (Blueprint $table): void {
            $table->json('cc_addresses')->nullable();
            $table->foreignId('merged_into_work_item_id')->nullable()->constrained('work_items')->nullOnDelete();
        });

        Schema::table('work_item_messages', function (Blueprint $table): void {
            $table->json('cc_addresses')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('work_item_messages', function (Blueprint $table): void {
            $table->dropColumn('cc_addresses');
        });

        Schema::table('work_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('merged_into_work_item_id');
            $table->dropColumn('cc_addresses');
        });

        Schema::dropIfExists('work_item_watchers');
        Schema::dropIfExists('work_item_links');
        Schema::dropIfExists('canned_responses');
    }
};
