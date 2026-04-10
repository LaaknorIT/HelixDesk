<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $guard = config('auth.defaults.guard', 'web');
        $teamKey = config('permission.column_names.team_foreign_key', 'team_id');

        Role::query()
            ->where('name', 'Admin')
            ->where('guard_name', $guard)
            ->each(function (Role $role) use ($guard, $teamKey): void {
                $teamId = $role->getAttribute($teamKey);

                $exists = Role::query()
                    ->where($teamKey, $teamId)
                    ->where('name', 'Tenant Admin')
                    ->where('guard_name', $guard)
                    ->exists();

                if ($exists) {
                    return;
                }

                $role->update(['name' => 'Tenant Admin']);
            });

        DB::table('dashboard_layouts')
            ->where('role_name', 'Admin')
            ->update(['role_name' => 'Tenant Admin']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $guard = config('auth.defaults.guard', 'web');
        $teamKey = config('permission.column_names.team_foreign_key', 'team_id');

        Role::query()
            ->where('name', 'Tenant Admin')
            ->where('guard_name', $guard)
            ->each(function (Role $role) use ($guard, $teamKey): void {
                $teamId = $role->getAttribute($teamKey);

                $exists = Role::query()
                    ->where($teamKey, $teamId)
                    ->where('name', 'Admin')
                    ->where('guard_name', $guard)
                    ->exists();

                if ($exists) {
                    return;
                }

                $role->update(['name' => 'Admin']);
            });

        DB::table('dashboard_layouts')
            ->where('role_name', 'Tenant Admin')
            ->update(['role_name' => 'Admin']);
    }
};
