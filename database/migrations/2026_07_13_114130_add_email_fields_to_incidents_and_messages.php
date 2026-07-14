<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->string('source')->default('portal')->after('status');
            $table->unsignedBigInteger('mail_account_id')->nullable()->after('source');
            $table->string('inbound_message_id')->nullable()->after('mail_account_id');
        });

        Schema::table('incident_messages', function (Blueprint $table) {
            $table->unsignedBigInteger('author_user_id')->nullable()->change();
            $table->string('external_message_id')->nullable()->after('body');
            $table->string('in_reply_to')->nullable()->after('external_message_id');
            $table->text('email_references')->nullable()->after('in_reply_to');
            $table->string('from_address')->nullable()->after('email_references');
            $table->json('to_addresses')->nullable()->after('from_address');
            $table->unsignedBigInteger('mail_account_id')->nullable()->after('to_addresses');
            $table->timestamp('email_sent_at')->nullable()->after('mail_account_id');

            $table->index('external_message_id');
        });
    }

    public function down(): void
    {
        Schema::table('incident_messages', function (Blueprint $table) {
            $table->dropIndex(['external_message_id']);
            $table->dropColumn([
                'external_message_id',
                'in_reply_to',
                'email_references',
                'from_address',
                'to_addresses',
                'mail_account_id',
                'email_sent_at',
            ]);
            $table->unsignedBigInteger('author_user_id')->nullable(false)->change();
        });

        Schema::table('incidents', function (Blueprint $table) {
            $table->dropColumn(['source', 'mail_account_id', 'inbound_message_id']);
        });
    }
};
