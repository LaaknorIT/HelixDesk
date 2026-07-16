<?php

use App\Services\Mail\WorkItemPublicIdGenerator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_accounts', function (Blueprint $table) {
            $table->boolean('supports_plus_addressing')->default(false)->after('is_enabled');
        });

        Schema::table('work_items', function (Blueprint $table) {
            $table->string('public_id', 32)->nullable()->after('inbound_message_id');
        });

        $generator = app(WorkItemPublicIdGenerator::class);

        $rows = DB::table('work_items')->select(['id', 'tenant_id', 'public_id'])->orderBy('id')->get();
        $issuedByTenant = [];

        foreach ($rows as $row) {
            if (is_string($row->public_id) && $row->public_id !== '') {
                $issuedByTenant[(int) $row->tenant_id][] = $row->public_id;

                continue;
            }

            $tenantId = (int) $row->tenant_id;
            $issuedByTenant[$tenantId] ??= [];
            $publicId = $generator->generate($tenantId, $issuedByTenant[$tenantId]);
            $issuedByTenant[$tenantId][] = $publicId;

            DB::table('work_items')->where('id', $row->id)->update(['public_id' => $publicId]);
        }

        Schema::table('work_items', function (Blueprint $table) {
            $table->string('public_id', 32)->nullable(false)->change();
            $table->unique(['tenant_id', 'public_id']);
        });
    }

    public function down(): void
    {
        Schema::table('work_items', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'public_id']);
            $table->dropColumn('public_id');
        });

        Schema::table('mail_accounts', function (Blueprint $table) {
            $table->dropColumn('supports_plus_addressing');
        });
    }
};
