<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Users synced from an identity provider sign in through that provider, so they must not
     * keep a local password. Allow a null password and remove the random ones set by earlier syncs.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
        });

        DB::table('users')
            ->whereNotNull('entra_object_id')
            ->update(['password' => null, 'remember_token' => null]);
    }

    public function down(): void
    {
        DB::table('users')
            ->whereNull('password')
            ->update(['password' => '']);

        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable(false)->change();
        });
    }
};
