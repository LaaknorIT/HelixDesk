<?php

namespace App\Services\Auth;

use App\Models\Organization;
use App\Models\OrganizationIntegration;
use App\Models\User;
use App\Support\OrganizationIntegrationResolver;
use App\Support\OrganizationIntegrationSettings;
use Filament\Panel;
use Laravel\Socialite\Facades\Socialite;
use SocialiteProviders\Azure\Provider as AzureProvider;
use SocialiteProviders\Manager\Config as SocialiteConfig;
use Throwable;

/**
 * Signs users synced from Microsoft Entra in through the Entra tenant they were synced from.
 *
 * Every customer organization has its own app registration in `organization_integrations`,
 * so the provider is configured per sign-in rather than from config/services.php.
 */
class EntraSignInService
{
    public const SESSION_KEY = 'entra_sign_in';

    /**
     * Find the synced user and the Entra integration to send them to.
     *
     * @return array{user: User, integration: OrganizationIntegration, config: array{azure_tenant_id: string, application_client_id: string, client_secret: string}}|null
     */
    public function resolveForEmail(string $email, Panel $panel): ?array
    {
        $user = User::query()
            ->where('email', $email)
            ->whereNotNull('entra_object_id')
            ->first();

        if ($user === null || ! $user->canAccessPanel($panel)) {
            return null;
        }

        $integration = $this->integrationForUser($user);
        if ($integration === null) {
            return null;
        }

        $config = $this->signInConfig($integration);
        if ($config === null) {
            return null;
        }

        return ['user' => $user, 'integration' => $integration, 'config' => $config];
    }

    /**
     * @param  array{azure_tenant_id: string, application_client_id: string, client_secret: string}  $config
     */
    public function provider(array $config): AzureProvider
    {
        /** @var AzureProvider $provider */
        $provider = Socialite::driver('azure');

        $provider->setConfig(new SocialiteConfig(
            $config['application_client_id'],
            $config['client_secret'],
            route('auth.microsoft.callback'),
            ['tenant' => $config['azure_tenant_id']],
        ));

        return $provider->scopes(['openid', 'profile', 'email']);
    }

    /**
     * Use the integration the user was synced from, or for users synced before that was
     * recorded, the nearest Microsoft Entra integration up the user's organization tree.
     */
    public function integrationForUser(User $user): ?OrganizationIntegration
    {
        if (filled($user->identity_integration_id)) {
            $integration = OrganizationIntegration::query()
                ->withoutGlobalScope('tenant')
                ->whereKey($user->identity_integration_id)
                ->where('tenant_id', $user->tenant_id)
                ->where('type', OrganizationIntegrationSettings::TYPE_MICROSOFT_ENTRA)
                ->first();

            if ($integration !== null) {
                return $integration;
            }
        }

        $organization = $user->organization_id !== null ? Organization::query()->withoutGlobalScope('tenant')->find($user->organization_id) : null;
        $visited = [];

        while ($organization !== null && ! in_array($organization->getKey(), $visited, true)) {
            $visited[] = $organization->getKey();

            $integration = OrganizationIntegration::query()
                ->withoutGlobalScope('tenant')
                ->where('tenant_id', $user->tenant_id)
                ->where('organization_id', $organization->getKey())
                ->where('type', OrganizationIntegrationSettings::TYPE_MICROSOFT_ENTRA)
                ->first();

            if ($integration !== null) {
                return $integration;
            }

            $organization = $organization->parent_id !== null ? Organization::query()->withoutGlobalScope('tenant')->find($organization->parent_id) : null;
        }

        return null;
    }

    /**
     * @return array{azure_tenant_id: string, application_client_id: string, client_secret: string}|null
     */
    public function signInConfig(OrganizationIntegration $integration): ?array
    {
        try {
            $entry = OrganizationIntegrationResolver::resolve(
                (int) $integration->organization_id,
                (string) $integration->getKey(),
                OrganizationIntegrationSettings::TYPE_MICROSOFT_ENTRA,
            );
        } catch (Throwable) {
            return null;
        }

        $config = is_array($entry['config'] ?? null) ? $entry['config'] : [];

        if (! ($config['enabled'] ?? false)) {
            return null;
        }

        $signInConfig = [
            'azure_tenant_id' => trim((string) ($config['azure_tenant_id'] ?? '')),
            'application_client_id' => trim((string) ($config['application_client_id'] ?? '')),
            'client_secret' => trim((string) ($config['client_secret'] ?? '')),
        ];

        return in_array('', $signInConfig, true) ? null : $signInConfig;
    }
}
