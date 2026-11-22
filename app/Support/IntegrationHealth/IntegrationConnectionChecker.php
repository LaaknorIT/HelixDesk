<?php

namespace App\Support\IntegrationHealth;

use App\Models\OrganizationIntegration;
use App\Services\Action1\Action1HttpClient;
use App\Services\MicrosoftEntra\MicrosoftGraphHttpClient;
use App\Support\Action1SyncTarget;
use App\Support\MicrosoftEntraIntegrationCatalog;
use Throwable;

/**
 * Verifies credentials against the provider right away (without running a full sync) and records the result,
 * so administrators get immediate feedback on secrets and API permissions.
 */
final class IntegrationConnectionChecker
{
    public static function supports(OrganizationIntegration $integration): bool
    {
        return $integration->isMicrosoftEntra() || $integration->isAction1();
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public static function check(OrganizationIntegration $integration): array
    {
        try {
            $result = match (true) {
                $integration->isMicrosoftEntra() => self::checkMicrosoftEntra($integration),
                $integration->isAction1() => self::checkAction1($integration),
                default => ['ok' => false, 'message' => __('Connection checks are not available for this integration yet.')],
            };
        } catch (Throwable $exception) {
            $result = ['ok' => false, 'message' => $exception->getMessage()];
        }

        IntegrationHealthRecorder::recordConnectionCheck((string) $integration->getKey(), $result['ok'], $result['message']);

        return $result;
    }

    /**
     * @return array{ok: bool, message: string}
     */
    private static function checkMicrosoftEntra(OrganizationIntegration $integration): array
    {
        $config = $integration->configWithCredentials();
        $client = new MicrosoftGraphHttpClient(
            directoryTenantId: trim((string) ($config['azure_tenant_id'] ?? '')),
            clientId: trim((string) ($config['application_client_id'] ?? '')),
            clientSecret: trim((string) ($config['client_secret'] ?? '')),
        );
        $client->authenticate();

        $granted = $client->grantedApplicationPermissions();
        $secretExpiresAt = $granted !== null && array_intersect($granted, ['Application.Read.All', 'Application.ReadWrite.All', 'Application.ReadWrite.OwnedBy']) !== []
            ? $client->clientSecretExpiresAt()
            : null;

        IntegrationHealthRecorder::recordGrantedPermissions((string) $integration->getKey(), $granted, $secretExpiresAt);

        if ($granted === null) {
            return ['ok' => true, 'message' => __('Signed in to Microsoft Entra ID.')];
        }

        $scopes = is_array($config['scopes'] ?? null) ? $config['scopes'] : [];
        $missing = [];
        foreach (array_keys(MicrosoftEntraIntegrationCatalog::runtimePermissionRequirements()) as $scopeKey) {
            if ($scopes[$scopeKey] ?? false) {
                $missing = [...$missing, ...MicrosoftEntraIntegrationCatalog::missingPermissionsForScope($scopeKey, $granted)];
            }
        }
        $missing = array_values(array_unique($missing));

        if ($missing !== []) {
            return ['ok' => true, 'message' => __('Signed in, but these permissions are missing: :permissions.', [
                'permissions' => implode(', ', $missing),
            ])];
        }

        return ['ok' => true, 'message' => __('Signed in. All permissions needed for the enabled capabilities are granted.')];
    }

    /**
     * @return array{ok: bool, message: string}
     */
    private static function checkAction1(OrganizationIntegration $integration): array
    {
        $client = Action1HttpClient::fromTarget(new Action1SyncTarget(
            tenantId: (int) $integration->tenant_id,
            organizationId: (int) $integration->organization_id,
            integrationId: (string) $integration->getKey(),
            config: $integration->configWithCredentials(),
            credentials: $integration->mergedCredentials(),
            apiBaseUrl: $integration->api_base_url,
        ));
        $client->authenticate();

        return ['ok' => true, 'message' => __('Action1 accepted the API credentials.')];
    }
}
