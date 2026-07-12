<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            if (! Schema::hasColumn('organizations', 'type')) {
                $table->string('type')->default('customer')->after('tenant_id');
            }
            if (! Schema::hasColumn('organizations', 'code')) {
                $table->string('code')->nullable()->after('name');
            }
            if (! Schema::hasColumn('organizations', 'is_billable')) {
                $table->boolean('is_billable')->default(false)->after('code');
            }
            if (! Schema::hasColumn('organizations', 'order')) {
                $table->unsignedInteger('order')->default(0)->after('is_billable');
            }
            if (! Schema::hasColumn('organizations', 'parent_id')) {
                $table->bigInteger('parent_id')->default(-1)->after('order');
            }
        });

        if (Schema::hasColumn('organizations', 'parent_organization_id')) {
            DB::table('organizations')->update([
                'parent_id' => DB::raw('COALESCE(parent_organization_id, -1)'),
            ]);

            Schema::table('organizations', function (Blueprint $table) {
                $table->dropForeign(['parent_organization_id']);
            });

            Schema::table('organizations', function (Blueprint $table) {
                $table->dropIndex(['tenant_id', 'parent_organization_id']);
                $table->dropColumn('parent_organization_id');
            });
        }

        DB::table('organizations')->update([
            'type' => 'customer',
            'is_billable' => true,
        ]);

        if (! Schema::hasTable('departments')) {
            Schema::table('organizations', function (Blueprint $table) {
                $table->index(['tenant_id', 'parent_id']);
                $table->index(['tenant_id', 'type']);
            });

            $this->dropDepartmentForeignKeys();

            return;
        }

        $departmentMap = $this->migrateDepartmentsToOrganizations();

        foreach (['users', 'assets', 'incidents'] as $tableName) {
            if (! Schema::hasColumn($tableName, 'department_id')) {
                continue;
            }

            foreach ($departmentMap as $oldDepartmentId => $newOrganizationId) {
                DB::table($tableName)
                    ->where('department_id', $oldDepartmentId)
                    ->update(['organization_id' => $newOrganizationId]);
            }
        }

        $this->dropDepartmentForeignKeys();

        Schema::dropIfExists('departments');

        Schema::table('organizations', function (Blueprint $table) {
            $table->index(['tenant_id', 'parent_id']);
            $table->index(['tenant_id', 'type']);
        });
    }

    /**
     * @return array<int, int>
     */
    private function migrateDepartmentsToOrganizations(): array
    {
        $map = [];
        $remaining = DB::table('departments')->get()->keyBy('id');

        while ($remaining->isNotEmpty()) {
            $progress = false;

            foreach ($remaining as $departmentId => $department) {
                $parentDepartmentId = $department->parent_department_id;

                if ($parentDepartmentId !== null && ! array_key_exists((int) $parentDepartmentId, $map)) {
                    continue;
                }

                $parentId = $parentDepartmentId !== null
                    ? $map[(int) $parentDepartmentId]
                    : (int) $department->organization_id;

                $newId = DB::table('organizations')->insertGetId([
                    'tenant_id' => $department->tenant_id,
                    'type' => 'department',
                    'name' => $department->name,
                    'code' => $department->code,
                    'is_billable' => false,
                    'order' => 0,
                    'parent_id' => $parentId,
                    'external_reference' => $department->external_reference,
                    'organizational_settings' => null,
                    'created_at' => $department->created_at,
                    'updated_at' => $department->updated_at,
                ]);

                $map[(int) $departmentId] = (int) $newId;
                $remaining->forget($departmentId);
                $progress = true;
            }

            if (! $progress) {
                throw new RuntimeException('Unable to migrate department hierarchy: circular or orphaned departments detected.');
            }
        }

        return $map;
    }

    private function dropDepartmentForeignKeys(): void
    {
        foreach (['users', 'assets', 'incidents'] as $tableName) {
            if (! Schema::hasColumn($tableName, 'department_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->dropForeign(['department_id']);
            });

            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn('department_id');
            });
        }
    }

    public function down(): void
    {
        throw new RuntimeException('This migration cannot be reversed automatically.');
    }
};
