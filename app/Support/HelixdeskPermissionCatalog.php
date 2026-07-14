<?php

namespace App\Support;

/**
 * Resolves module.action permissions from config/helixdesk_permissions.php.
 */
final class HelixdeskPermissionCatalog
{
    /**
     * @return list<string> All permission names (e.g. work_item.read).
     */
    public static function allPermissionNames(): array
    {
        $names = [];

        foreach (config('helixdesk_permissions.modules', []) as $moduleKey => $module) {
            foreach ($module['actions'] as $action) {
                $names[] = $moduleKey.'.'.$action;
            }
        }

        return $names;
    }

    /**
     * @param  array<int, string>|string  $assignment  '*' or list of names / module.* wildcards
     * @return list<string>
     */
    public static function expandRoleAssignment(array|string $assignment): array
    {
        if ($assignment === '*') {
            return self::allPermissionNames();
        }

        $out = [];

        foreach ((array) $assignment as $token) {
            if (str_ends_with($token, '.*')) {
                $mod = substr($token, 0, -2);
                $module = config('helixdesk_permissions.modules.'.$mod);

                if (is_array($module) && isset($module['actions'])) {
                    foreach ($module['actions'] as $action) {
                        $out[] = $mod.'.'.$action;
                    }
                }

                continue;
            }

            $out[] = $token;
        }

        return array_values(array_unique($out));
    }

    /**
     * @return array<string, array{label: string, actions: list<string>}>
     */
    public static function modulesWithActions(): array
    {
        return config('helixdesk_permissions.modules', []);
    }

    /**
     * Modules shown on the global role matrix (work item is configured per queue).
     *
     * @return array<string, array{label: string, actions: list<string>}>
     */
    public static function modulesWithActionsExcludingWorkItem(): array
    {
        $modules = self::modulesWithActions();
        unset($modules['work_item']);

        return $modules;
    }

    /**
     * @return list<string>
     */
    public static function workItemModuleActions(): array
    {
        return config('helixdesk_permissions.modules.work_item.actions', []);
    }

    /**
     * @return array<string, string>
     */
    public static function actionLabels(): array
    {
        return config('helixdesk_permissions.action_labels', []);
    }

    /**
     * Union of all action keys used by any module (matrix columns).
     *
     * @return list<string>
     */
    public static function allActionsForMatrix(): array
    {
        $actions = [];

        foreach (config('helixdesk_permissions.modules', []) as $module) {
            foreach ($module['actions'] as $action) {
                $actions[$action] = true;
            }
        }

        return array_keys($actions);
    }

    /**
     * Consistent column order for the permission matrix UI.
     *
     * @return list<string>
     */
    public static function orderedMatrixActions(): array
    {
        $preferred = ['read', 'create', 'update', 'delete', 'approve', 'admin'];
        $present = self::allActionsForMatrix();
        $ordered = array_values(array_filter($preferred, fn (string $a): bool => in_array($a, $present, true)));
        $rest = array_values(array_diff($present, $ordered));
        sort($rest);

        return [...$ordered, ...$rest];
    }
}
