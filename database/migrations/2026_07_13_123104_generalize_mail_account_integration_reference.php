<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_accounts', function (Blueprint $table) {
            $table->string('integration_type')->nullable()->after('organization_id');
        });

        if (Schema::hasColumn('mail_accounts', 'entra_integration_id')) {
            DB::table('mail_accounts')
                ->whereNotNull('entra_integration_id')
                ->update(['integration_type' => 'microsoft_entra']);

            Schema::table('mail_accounts', function (Blueprint $table) {
                $table->renameColumn('entra_integration_id', 'integration_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('mail_accounts', 'integration_id')) {
            Schema::table('mail_accounts', function (Blueprint $table) {
                $table->renameColumn('integration_id', 'entra_integration_id');
            });
        }

        Schema::table('mail_accounts', function (Blueprint $table) {
            $table->dropColumn('integration_type');
        });
    }
};
