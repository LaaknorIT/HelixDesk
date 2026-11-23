<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An integration with no organization sits at the tenant root, above every organization (for example an MSP-wide
 * RMM). Rows that record what such an integration found carry no organization either until a device moves down.
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private array $tables = [
        'organization_integrations',
        'asset_external_identifiers',
        'asset_identity_ambiguities',
        'asset_source_records',
    ];

    public function up(): void
    {
        foreach ($this->tables as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->unsignedBigInteger('organization_id')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        DB::table('asset_source_records')->whereNull('organization_id')->delete();
        DB::table('asset_identity_ambiguities')->whereNull('organization_id')->delete();
        DB::table('asset_external_identifiers')->whereNull('organization_id')->delete();
        DB::table('organization_integrations')->whereNull('organization_id')->delete();

        foreach ($this->tables as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->unsignedBigInteger('organization_id')->nullable(false)->change();
            });
        }
    }
};
