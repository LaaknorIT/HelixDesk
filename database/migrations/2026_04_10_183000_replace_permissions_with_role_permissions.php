<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $guard = config('auth.defaults.guard', 'web');
        $roleHasPermissions = config('permission.table_names.role_has_permissions');
        $permissionsTable = config('permission.table_names.permissions');
        $modelHasPermissions = config('permission.table_names.model_has_permissions');

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->string('permission');
            $table->string('guard_name');
            $table->timestamps();

            $table->unique(['role_id', 'permission', 'guard_name'], 'role_permissions_unique');
            $table->index('permission', 'role_permissions_permission_index');
        });

        if (Schema::hasTable($roleHasPermissions) && Schema::hasTable($permissionsTable)) {
            $rows = DB::table($roleHasPermissions)
                ->join($permissionsTable, $permissionsTable.'.id', '=', $roleHasPermissions.'.permission_id')
                ->select([
                    $roleHasPermissions.'.role_id',
                    $permissionsTable.'.name as permission',
                    $permissionsTable.'.guard_name',
                ])
                ->get();

            foreach ($rows as $row) {
                DB::table('role_permissions')->updateOrInsert(
                    [
                        'role_id' => $row->role_id,
                        'permission' => $row->permission,
                        'guard_name' => $row->guard_name,
                    ],
                    [
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
        }

        Schema::dropIfExists($modelHasPermissions);
        Schema::dropIfExists($roleHasPermissions);
        Schema::dropIfExists($permissionsTable);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Intentionally not reversed: restoring Spatie `permissions` + FK pivots is non-trivial.
    }
};
