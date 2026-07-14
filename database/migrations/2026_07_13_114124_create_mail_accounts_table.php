<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('name');
            $table->string('email_address');
            $table->string('protocol');
            $table->string('outbound_protocol');
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->string('entra_integration_id')->nullable();
            $table->text('connection_settings')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->timestamp('last_polled_at')->nullable();
            $table->text('last_poll_error')->nullable();
            $table->json('sync_state')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'is_enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_accounts');
    }
};
