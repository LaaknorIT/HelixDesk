<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Spatie\Permission\Contracts\Role as RoleContract;
use Spatie\Permission\PermissionRegistrar;

final class RolePermissionSync
{
    /**
     * @param  list<string>  $names
     */
    public static function sync(RoleContract $role, array $names, ?string $guardName = null): void
    {
        $guardName ??= config('auth.defaults.guard', 'web');
        $names = array_values(array_unique($names));

        DB::transaction(function () use ($role, $names, $guardName): void {
            DB::table('role_permissions')
                ->where('role_id', $role->getKey())
                ->where('guard_name', $guardName)
                ->delete();

            $now = now();

            foreach ($names as $name) {
                DB::table('role_permissions')->insert([
                    'role_id' => $role->getKey(),
                    'permission' => $name,
                    'guard_name' => $guardName,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @return list<string>
     */
    public static function namesForRole(RoleContract $role, ?string $guardName = null): array
    {
        $guardName ??= config('auth.defaults.guard', 'web');

        return DB::table('role_permissions')
            ->where('role_id', $role->getKey())
            ->where('guard_name', $guardName)
            ->orderBy('permission')
            ->pluck('permission')
            ->map(fn ($p): string => (string) $p)
            ->all();
    }
}
