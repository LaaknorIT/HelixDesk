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
        Schema::create('public_page_settings', function (Blueprint $table) {
            $table->id();
            $table->string('headline');
            $table->text('introduction')->nullable();
            $table->boolean('show_announcement')->default(false);
            $table->string('announcement_title')->nullable();
            $table->text('announcement_body')->nullable();
            $table->boolean('show_contact')->default(false);
            $table->string('contact_email')->nullable();
            $table->string('contact_phone')->nullable();
            $table->string('contact_hours')->nullable();
            $table->json('sections')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('public_page_settings');
    }
};
