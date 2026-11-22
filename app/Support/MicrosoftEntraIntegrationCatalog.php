<?php

namespace App\Support;

use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\HtmlString;

/**
 * Microsoft Entra (Azure AD) integration: scope labels and Microsoft Graph
 * application permissions commonly used for directory / Intune / security sync.
 *
 * Permission names must be verified in the Microsoft Graph documentation and Entra portal when registering the application.
 */
final class MicrosoftEntraIntegrationCatalog
{
    /**
     * @return array<string, array{label: string, description: string, application_permissions: list<string>, setup_notes: string}>
     */
    public static function scopeDefinitions(): array
    {
        return [
            'users' => [
                'label' => 'Users',
                'description' => 'Provision and update people in HelixDesk from Microsoft Entra ID directory users.',
                'application_permissions' => [
                    'User.Read.All',
                ],
                'setup_notes' => 'Allows the app to read user profiles without a signed-in user. Grant admin consent in Entra.',
            ],
            'departments' => [
                'label' => 'Departments',
                'description' => 'Map organizational structure (e.g. groups or administrative units) into HelixDesk departments.',
                'application_permissions' => [
                    'Group.Read.All',
                    'Directory.Read.All',
                ],
                'setup_notes' => 'Group.Read.All covers security groups often used for org structure. Directory.Read.All may be required for broader directory reads—confirm least privilege with your design.',
            ],
            'computers' => [
                'label' => 'Computers (Intune-managed devices)',
                'description' => 'Sync managed devices as configuration items / assets (e.g. from Intune).',
                'application_permissions' => [
                    'DeviceManagementManagedDevices.Read.All',
                ],
                'setup_notes' => 'Requires Intune / Microsoft Endpoint Manager in the tenant. Use read-only scope first; expand only if workflows need writes.',
            ],
            'security_work_items' => [
                'label' => 'Security incidents',
                'description' => 'Ingest Microsoft 365 Defender / unified security incidents for correlation with service desk work.',
                'application_permissions' => [
                    'SecurityIncident.Read.All',
                ],
                'setup_notes' => 'Exact API surface evolves with Microsoft Graph security APIs—verify the permission exists for your tenant SKU and that incident export matches your compliance policy.',
            ],
            'mail' => [
                'label' => 'Mail (shared mailboxes)',
                'description' => 'Poll shared mailboxes, mark processed messages as read, and send incident replies via Microsoft Graph.',
                'application_permissions' => [
                    'Mail.ReadWrite',
                    'Mail.Send',
                ],
                'setup_notes' => 'In Entra: App registration → API permissions → Microsoft Graph → Application permissions → add Mail.ReadWrite and Mail.Send → Grant admin consent. Mail.Read alone can fetch mail but cannot mark messages read after ingest. Then set each HelixDesk mail account mailbox UPN to the shared mailbox address. A 403 ErrorAccessDenied usually means these permissions are missing or not consented.',
            ],
        ];
    }

    /**
     * Microsoft Graph application permissions HelixDesk actually needs at runtime per capability,
     * mapped to the permissions that satisfy each requirement (broader permissions also count).
     *
     * @return array<string, array<string, list<string>>> scope key => required permission => accepted permissions
     */
    public static function runtimePermissionRequirements(): array
    {
        $userRead = ['User.Read.All', 'User.ReadWrite.All', 'Directory.Read.All', 'Directory.ReadWrite.All'];

        return [
            'users' => ['User.Read.All' => $userRead],
            'departments' => ['User.Read.All' => $userRead],
            'computers' => [
                'DeviceManagementManagedDevices.Read.All' => [
                    'DeviceManagementManagedDevices.Read.All',
                    'DeviceManagementManagedDevices.ReadWrite.All',
                ],
            ],
            'security_work_items' => [
                'SecurityIncident.Read.All' => [
                    'SecurityIncident.Read.All',
                    'SecurityIncident.ReadWrite.All',
                ],
            ],
            'mail' => [
                'Mail.ReadWrite' => ['Mail.ReadWrite'],
                'Mail.Send' => ['Mail.Send'],
            ],
        ];
    }

    /**
     * Required permissions that are not covered by the granted application permissions.
     *
     * @param  list<string>  $grantedPermissions
     * @return list<string>
     */
    public static function missingPermissionsForScope(string $scopeKey, array $grantedPermissions): array
    {
        $requirements = self::runtimePermissionRequirements()[$scopeKey] ?? [];
        $granted = array_map('strtolower', $grantedPermissions);
        $missing = [];

        foreach ($requirements as $required => $accepted) {
            $satisfied = false;
            foreach ($accepted as $permission) {
                if (in_array(strtolower($permission), $granted, true)) {
                    $satisfied = true;

                    break;
                }
            }

            if (! $satisfied) {
                $missing[] = $required;
            }
        }

        return $missing;
    }

    /**
     * Scope keys that drive directory / Intune / security sync jobs (not mail).
     *
     * @return list<string>
     */
    public static function directorySyncScopeKeys(): array
    {
        return ['users', 'departments', 'computers', 'security_work_items'];
    }

    /**
     * @param  array<string, bool>  $scopesEnabled  keys: users, departments, computers, security_work_items, mail
     */
    public static function collectApplicationPermissions(array $scopesEnabled): array
    {
        $defs = self::scopeDefinitions();
        $perms = [];

        foreach ($scopesEnabled as $key => $on) {
            if (! $on || ! isset($defs[$key])) {
                continue;
            }

            foreach ($defs[$key]['application_permissions'] as $p) {
                $perms[$p] = true;
            }
        }

        return array_keys($perms);
    }

    /**
     * @param  array<string, bool>  $scopesEnabled
     */
    public static function summaryHtml(array $scopesEnabled): HtmlString
    {
        $defs = self::scopeDefinitions();
        $any = array_filter($scopesEnabled);

        if ($any === []) {
            return new HtmlString(
                '<div class="px-4 py-3">'
                .'<p class="">'.e(__('Enable at least one capability in the previous step to see the suggested Microsoft Graph application permissions.')).'</p>'
                .'</div>'
            );
        }

        $lines = ['<div class="space-y-4">'];

        foreach ($scopesEnabled as $key => $on) {
            if (! $on || ! isset($defs[$key])) {
                continue;
            }

            $d = $defs[$key];
            $permList = array_map(static fn (string $p): string => '<code class="text-xs px-1.5 py-0.5 font-mono">'.e($p).'</code>', $d['application_permissions']);
            $lines[] = '<div class="p-4">';
            $lines[] = '<p class="font-semibold text-base">'.e($d['label']).'</p>';
            $lines[] = '<p class="mt-1.5 leading-relaxed">'.e($d['description']).'</p>';
            $lines[] = '<p class="mt-3 text-xs font-semibold uppercase tracking-wide">'.e(__('Suggested application permissions')).'</p>';
            $lines[] = '<ul class="mt-2 flex flex-wrap gap-2">'.implode('', array_map(static fn (string $li): string => '<li class="list-none">'.$li.'</li>', $permList)).'</ul>';
            $lines[] = '<p class="mt-3 text-xs leading-relaxed pl-3">'.e($d['setup_notes']).'</p>';
            $lines[] = '</div>';
        }

        $all = self::collectApplicationPermissions($scopesEnabled);
        $lines[] = '<div class="p-4">';
        $lines[] = '<p class="text-xs font-semibold">'.e(__('Combined unique application permissions')).'</p>';
        $lines[] = '<p class="text-xs mt-1">'.e(__('Add in App registration → API permissions → Microsoft Graph → Application permissions, then grant admin consent.')).'</p>';
        $lines[] = '<p class="mt-3 font-mono text-xs break-all px-3 py-2">'.e(implode(', ', $all)).'</p>';
        $lines[] = '</div>';

        $lines[] = '<p class="text-xs leading-relaxed">'
            .e(__('After adding permissions, use '))
            .'<strong>'.e(__('Grant admin consent for {tenant}', ['tenant' => __('your organization')])).'</strong>'
            .e(__('. Delegated permissions are not required for the background sync pattern described here.'))
            .'</p>';
        $lines[] = '</div>';

        return new HtmlString(implode("\n", $lines));
    }

    /**
     * @return array<string, bool>
     */
    public static function scopesFromGet(Get $get): array
    {
        return [
            'users' => (bool) $get('entra_scope_users'),
            'departments' => (bool) $get('entra_scope_departments'),
            'computers' => (bool) $get('entra_scope_computers'),
            'security_work_items' => (bool) $get('entra_scope_security_work_items'),
            'mail' => (bool) $get('entra_scope_mail'),
        ];
    }

    public static function mailPermissionsSummaryHtml(): HtmlString
    {
        return self::summaryHtml([
            'mail' => true,
        ]);
    }
}
