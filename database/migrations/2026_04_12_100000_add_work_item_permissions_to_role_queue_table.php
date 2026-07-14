<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('role_queue', function (Blueprint $table) {
            $table->json('work_item_permissions')->nullable()->after('queue_id');
        });

        $guard = config('auth.defaults.guard', 'web');
        $workItemActions = config('helixdesk_permissions.modules.work_item.actions', []);

        foreach (DB::table('role_queue')->cursor() as $row) {
            $permissions = DB::table('role_permissions')
                ->where('role_id', $row->role_id)
                ->where('guard_name', $guard)
                ->where('permission', 'like', 'work_item.%')
                ->pluck('permission')
                ->all();

            $map = array_fill_keys($workItemActions, false);

            foreach ($permissions as $permission) {
                if ($permission === 'work_item.admin') {
                    foreach ($workItemActions as $action) {
                        $map[$action] = true;
                    }

                    break;
                }

                if (str_starts_with((string) $permission, 'work_item.')) {
                    $action = substr((string) $permission, strlen('work_item.'));
                    if (array_key_exists($action, $map)) {
                        $map[$action] = true;
                    }
                }
            }

            DB::table('role_queue')
                ->where('role_id', $row->role_id)
                ->where('queue_id', $row->queue_id)
                ->update(['work_item_permissions' => json_encode($map)]);
        }
    }

    public function down(): void
    {
        Schema::table('role_queue', function (Blueprint $table) {
            $table->dropColumn('work_item_permissions');
        });
    }
};
