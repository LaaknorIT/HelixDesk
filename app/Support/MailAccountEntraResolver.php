<?php

namespace App\Support;

use App\Models\MailAccount;
use RuntimeException;

final class MailAccountEntraResolver
{
    /**
     * @return array{azure_tenant_id: string, application_client_id: string, client_secret: string, mailbox_upn: string}
     */
    public function resolve(MailAccount $account): array
    {
        $entry = OrganizationIntegrationResolver::resolveMailAccountIntegration($account);

        if (($entry['type'] ?? '') !== OrganizationIntegrationSettings::TYPE_MICROSOFT_ENTRA) {
            throw new RuntimeException('Mail account integration is not Microsoft Entra.');
        }

        $config = is_array($entry['config'] ?? null) ? $entry['config'] : [];
        $settings = is_array($account->connection_settings) ? $account->connection_settings : [];
        $mailboxUpn = trim((string) ($settings['mailbox_upn'] ?? $account->email_address));

        return [
            'azure_tenant_id' => trim((string) ($config['azure_tenant_id'] ?? '')),
            'application_client_id' => trim((string) ($config['application_client_id'] ?? '')),
            'client_secret' => trim((string) ($config['client_secret'] ?? '')),
            'mailbox_upn' => $mailboxUpn,
        ];
    }
}
