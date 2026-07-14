<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('queue_mail_account', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('queue_id');
            $table->unsignedBigInteger('mail_account_id');
            $table->timestamps();

            $table->unique(['queue_id', 'mail_account_id']);
        });

        Schema::table('queues', function (Blueprint $table) {
            $table->unsignedBigInteger('default_mail_account_id')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('queues', function (Blueprint $table) {
            $table->dropColumn('default_mail_account_id');
        });

        Schema::dropIfExists('queue_mail_account');
    }
};
