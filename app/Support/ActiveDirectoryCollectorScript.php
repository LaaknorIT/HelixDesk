<?php

namespace App\Support;

use App\Models\OrganizationIntegration;

/**
 * The PowerShell collector, with this installation's URL and the integration ID filled in. The collector key is
 * never put in the file: the administrator passes it once with -Install -CollectorKey.
 */
final class ActiveDirectoryCollectorScript
{
    public const VERSION = '1.0.0';

    public const FILE_NAME = 'HelixDesk-ADCollector.ps1';

    public static function render(OrganizationIntegration $integration): string
    {
        $template = (string) file_get_contents(resource_path('collectors/active-directory/'.self::FILE_NAME));

        return strtr($template, [
            '__HELIXDESK_URL__' => rtrim((string) config('app.url'), '/'),
            '__INTEGRATION_ID__' => (string) $integration->getKey(),
            '__COLLECTOR_VERSION__' => self::VERSION,
        ]);
    }
}
