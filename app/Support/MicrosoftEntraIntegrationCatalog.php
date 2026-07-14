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
                'label' => 'Security work_items',
                'description' => 'Ingest Microsoft 365 Defender / unified security work_items for correlation with service desk work.',
                'application_permissions' => [
                    'SecurityIncident.Read.All',
                ],
                'setup_notes' => 'Exact API surface evolves with Microsoft Graph security APIs—verify the permission exists for your tenant SKU and that incident export matches your compliance policy.',
            ],
            'mail' => [
                'label' => 'Mail (shared mailboxes)',
                'description' => 'Poll shared mailboxes and send incident replies via Microsoft Graph.',
                'application_permissions' => [
                    'Mail.Read',
                    'Mail.Send',
                ],
                'setup_notes' => 'Grant admin consent. Use application permissions with the shared mailbox UPN on each mail account.',
            ],
        ];
    }

    /**
     * @param  array<string, bool>  $scopesEnabled  keys: users, departments, computers, security_work_items
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
                '<div class="rounded-xl border border-dashed border-gray-200 dark:border-gray-700 bg-gray-50/80 dark:bg-gray-900/40 px-4 py-3">'
                .'<p class="text-sm text-gray-600 dark:text-gray-400">'.e(__('Enable at least one capability in the previous step to see the suggested Microsoft Graph application permissions.')).'</p>'
                .'</div>'
            );
        }

        $lines = ['<div class="space-y-4 text-sm">'];

        foreach ($scopesEnabled as $key => $on) {
            if (! $on || ! isset($defs[$key])) {
                continue;
            }

            $d = $defs[$key];
            $permList = array_map(static fn (string $p): string => '<code class="text-xs bg-gray-100 dark:bg-gray-800 px-1.5 py-0.5 rounded-md font-mono text-gray-900 dark:text-gray-100">'.e($p).'</code>', $d['application_permissions']);
            $lines[] = '<div class="rounded-xl border border-gray-200/90 dark:border-gray-600/50 bg-white dark:bg-gray-950 shadow-sm p-4">';
            $lines[] = '<p class="font-semibold text-gray-950 dark:text-white text-base">'.e($d['label']).'</p>';
            $lines[] = '<p class="text-gray-600 dark:text-gray-400 mt-1.5 leading-relaxed">'.e($d['description']).'</p>';
            $lines[] = '<p class="mt-3 text-xs font-semibold uppercase tracking-wide text-sky-700 dark:text-sky-400">'.e(__('Suggested application permissions')).'</p>';
            $lines[] = '<ul class="mt-2 flex flex-wrap gap-2">'.implode('', array_map(static fn (string $li): string => '<li class="list-none">'.$li.'</li>', $permList)).'</ul>';
            $lines[] = '<p class="mt-3 text-xs leading-relaxed text-amber-800 dark:text-amber-300/90 border-l-2 border-amber-400/70 pl-3">'.e($d['setup_notes']).'</p>';
            $lines[] = '</div>';
        }

        $all = self::collectApplicationPermissions($scopesEnabled);
        $lines[] = '<div class="rounded-xl bg-gradient-to-br from-slate-50 to-gray-100/80 dark:from-gray-900 dark:to-gray-950 border border-gray-200/80 dark:border-gray-700 p-4">';
        $lines[] = '<p class="text-xs font-semibold text-gray-800 dark:text-gray-200">'.e(__('Combined unique application permissions')).'</p>';
        $lines[] = '<p class="text-xs text-gray-600 dark:text-gray-400 mt-1">'.e(__('Add in App registration → API permissions → Microsoft Graph → Application permissions, then grant admin consent.')).'</p>';
        $lines[] = '<p class="mt-3 font-mono text-xs break-all text-gray-900 dark:text-gray-100 bg-white/70 dark:bg-gray-900/60 rounded-lg px-3 py-2 border border-gray-200/60 dark:border-gray-700">'.e(implode(', ', $all)).'</p>';
        $lines[] = '</div>';

        $lines[] = '<p class="text-xs text-gray-600 dark:text-gray-400 leading-relaxed">'
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
        ];
    }
}
