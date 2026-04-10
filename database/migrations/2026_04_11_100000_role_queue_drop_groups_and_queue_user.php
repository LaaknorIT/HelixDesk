<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('group_queue');
        Schema::dropIfExists('group_user');
        Schema::dropIfExists('groups');
        Schema::dropIfExists('queue_user');

        if (! Schema::hasTable('role_queue')) {
            Schema::create('role_queue', function (Blueprint $table) {
                $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
                $table->foreignId('queue_id')->constrained('queues')->cascadeOnDelete();
                $table->timestamps();

                $table->primary(['role_id', 'queue_id']);
                $table->index('queue_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('role_queue');
    }
};
