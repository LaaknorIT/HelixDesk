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
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('doc_type');
            $table->string('title');
            $table->string('slug');
            $table->longText('content')->nullable();
            $table->string('status')->default('draft');
            $table->string('visibility')->default('authenticated');
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'slug']);
            $table->index(['tenant_id', 'doc_type', 'status']);
        });

        Schema::create('document_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('document_id')->constrained('documents')->cascadeOnDelete();
            $table->morphs('linkable');
            $table->timestamps();

            $table->unique(['document_id', 'linkable_type', 'linkable_id'], 'document_links_unique_target');
            $table->index(['tenant_id', 'linkable_type', 'linkable_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_links');
        Schema::dropIfExists('documents');
    }
};
