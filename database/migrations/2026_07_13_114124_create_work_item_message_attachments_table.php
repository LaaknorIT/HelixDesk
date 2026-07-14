<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_item_message_attachments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('work_item_message_id');
            $table->string('filename');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->string('storage_path');
            $table->timestamps();

            $table->index(['tenant_id', 'work_item_message_id'], 'wima_tenant_message_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_item_message_attachments');
    }
};
