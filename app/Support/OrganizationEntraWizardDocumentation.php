<?php

namespace App\Support;

use Illuminate\Support\HtmlString;

/**
 * Rich HTML copy for the Microsoft Entra integration wizard (no Markdown).
 */
final class OrganizationEntraWizardDocumentation
{
    private static function docShell(string $innerHtml): string
    {
        return '<div class="rounded-xl border border-gray-200/90 dark:border-gray-600/60 bg-white dark:bg-gray-950 shadow-sm overflow-hidden">'
            .'<div class="px-5 py-4 space-y-3 text-sm leading-relaxed text-gray-700 dark:text-gray-300">'
            .$innerHtml
            .'</div>'
            .'</div>';
    }

    private static function lead(string $html): string
    {
        return '<p class="text-base text-gray-900 dark:text-gray-100 font-medium leading-snug">'.$html.'</p>';
    }

    private static function muted(string $html): string
    {
        return '<p class="text-xs text-gray-500 dark:text-gray-400">'.$html.'</p>';
    }

    public static function overviewHtml(): HtmlString
    {
        $body = self::lead(
            e(__('Connect this organization to Microsoft Entra ID so HelixDesk can read directory data, sync Intune devices, and access shared mailboxes via Microsoft Graph. '))
            .'<span class="text-sky-600 dark:text-sky-400 font-semibold">'.e(__('Application')).'</span>'
            .e(__(' permissions in Entra must match the capabilities you enable below.'))
        )
            .'<ul class="mt-4 space-y-2.5 pl-1">'
            .self::bullet(e(__('Use a separate app registration for each HelixDesk environment (production, staging, test).')))
            .self::bullet(e(__('Use application permissions for unattended jobs (directory sync and mail); delegated permissions are not required for these flows.')))
            .self::bullet(e(__('After choosing capabilities, the next step lists the exact Graph permissions to add in Entra → API permissions → Grant admin consent.')))
            .'</ul>'
            .self::muted(
                e(__('Tip: keep the Entra admin center open in another tab while you work through the steps.'))
            );

        return new HtmlString(self::docShell($body));
    }

    public static function appRegistrationHtml(): HtmlString
    {
        $body = self::lead(e(__('Register an application in the Microsoft Entra admin center')))
                       .'<ol class="mt-4 space-y-3 list-decimal list-outside pl-5 marker:font-semibold marker:text-sky-600 dark:marker:text-sky-400">'
            .self::orderedItem(
                e(__('Go to '))
                .'<strong>'.e(__('Entra ID')).'</strong>'
                .e(__(' → '))
                .'<strong>'.e(__('App registrations')).'</strong>'
                .e(__(' → '))
                .'<strong>'.e(__('New registration')).'</strong>'
                .e(__('. '))
            )
            .self::orderedItem(e(__('Name the application (for example: HelixDesk + customer + environment).')))
            .self::orderedItem(
                e(__('Supported account types: usually '))
                .'<strong>'.e(__('Accounts in this organizational directory only')).'</strong>'
                .e(__(' for single-tenant customers.'))
            )
            .self::orderedItem(
                e(__('After registration, copy the '))
                .'<strong>'.e(__('Application (client) ID')).'</strong>'
                .e(__(' and '))
                .'<strong>'.e(__('Directory (tenant) ID')).'</strong>'
                .e(__(' from the overview blade.'))
            )
            .self::orderedItem(
                e(__('Under '))
                .'<strong>'.e(__('Certificates & secrets')).'</strong>'
                .e(', create a ')
                .'<strong>'.e(__('Client secret')).'</strong>'
                .e(__(' and paste it in the final step. The value is stored with this organization\'s settings—treat the database like a secret surface and rotate credentials if it may have been exposed.'))
            )
            .self::orderedItem(
                e(__('Under '))
                .'<strong>'.e(__('API permissions')).'</strong>'
                .e(__(' → Add → '))
                .'<strong>'.e(__('Microsoft Graph')).'</strong>'
                .e(__(' → '))
                .'<strong>'.e(__('Application permissions')).'</strong>'
                .e(', add the permissions suggested after you choose capabilities.')
            )
            .self::orderedItem(
                e(__('Use '))
                .'<strong>'.e(__('Grant admin consent')).'</strong>'
                .e(__(' for your organization.'))
            )
            .'</ol>'
            .'<div class="mt-4 rounded-lg bg-gray-50 dark:bg-gray-900/70 border border-gray-100 dark:border-gray-800 px-3 py-2.5">'
            .self::muted(
                '<span class="font-semibold text-gray-600 dark:text-gray-400">'.e(__('Documentation')).':</span> '
                .e(__('Microsoft identity platform and Microsoft Graph permission reference (see Microsoft Learn).'))
            )
            .'</div>';

        return new HtmlString(self::docShell($body));
    }

    public static function capabilitiesIntroHtml(): HtmlString
    {
        $body = '<p class="text-sm text-gray-800 dark:text-gray-200">'
            .e(__('Choose directory sync capabilities and/or Mail (shared mailboxes). You can change this later; the permissions you grant in Entra should stay aligned with these toggles. Enabling Mail alone is valid for ticket email without directory sync.'))
            .'</p>';

        return new HtmlString(self::docShell($body));
    }

    private static function bullet(string $html): string
    {
        return '<li class="flex gap-2.5">'
            .'<span class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-sky-500 dark:bg-sky-400" aria-hidden="true"></span>'
            .'<span>'.$html.'</span>'
            .'</li>';
    }

    private static function orderedItem(string $html): string
    {
        return '<li class="pl-1">'.$html.'</li>';
    }
}
