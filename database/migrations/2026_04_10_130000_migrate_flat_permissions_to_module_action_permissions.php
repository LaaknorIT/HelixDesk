<?php

use App\Support\HelixdeskPermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $guard = config('auth.defaults.guard', 'web');

        if (! Permission::query()->where('name', 'view_work_items')->where('guard_name', $guard)->exists()) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            return;
        }

        $map = [
            'view_work_items' => ['work_item.read'],
            'manage_work_items' => ['work_item.create', 'work_item.update', 'work_item.delete'],
            'manage_problems' => ['problem.read', 'problem.create', 'problem.update', 'problem.delete', 'problem.admin'],
            'manage_changes' => ['change.read', 'change.create', 'change.update', 'change.delete', 'change.admin'],
            'approve_changes' => ['change.approve'],
            'view_assets' => ['asset.read'],
            'manage_assets' => ['asset.create', 'asset.update', 'asset.delete', 'asset.admin'],
            'view_docs' => ['document.read'],
            'edit_docs' => ['document.create', 'document.update', 'document.delete', 'document.admin'],
            'manage_dashboards' => ['dashboard.read', 'dashboard.update', 'dashboard.admin'],
            'manage_permissions' => ['tenant.read', 'tenant.admin'],
        ];

        foreach (HelixdeskPermissionCatalog::allPermissionNames() as $name) {
            Permission::query()->firstOrCreate([
                'name' => $name,
                'guard_name' => $guard,
            ]);
        }

        Role::query()->where('guard_name', $guard)->each(function (Role $role) use ($map): void {
            $names = [];

            foreach ($role->permissions as $permission) {
                if (isset($map[$permission->name])) {
                    $names = array_merge($names, $map[$permission->name]);
                } else {
                    $names[] = $permission->name;
                }
            }

            $role->syncPermissions(array_values(array_unique($names)));
        });

        Permission::query()
            ->where('guard_name', $guard)
            ->whereIn('name', array_keys($map))
            ->delete();

        DB::table('workflow_transitions')
            ->where('required_permission', 'manage_work_items')
            ->update(['required_permission' => 'work_item.update']);

        DB::table('workflow_transitions')
            ->where('required_permission', 'manage_changes')
            ->update(['required_permission' => 'change.update']);

        DB::table('workflow_transitions')
            ->where('required_permission', 'approve_changes')
            ->update(['required_permission' => 'change.approve']);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Intentionally not reversed.
    }
};
