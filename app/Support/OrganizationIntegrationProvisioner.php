<?php

namespace App\Support;

use App\Models\Organization;
use App\Models\OrganizationIntegration;
use App\Repositories\OrganizationIntegrationRepository;
use InvalidArgumentException;

final class OrganizationIntegrationProvisioner
{
    /**
     * @param  array<string, mixed>  $formData
     * @return array{integration_id: string, organizational_settings: array<string, mixed>, integration: OrganizationIntegration}
     */
    public static function create(Organization $organization, string $integrationType, array $formData): array
    {
        $repository = app(OrganizationIntegrationRepository::class);

        $integration = match ($integrationType) {
            OrganizationIntegrationSettings::TYPE_MICROSOFT_ENTRA => $repository->createMicrosoftEntraFromForm(
                $organization,
                $formData,
            ),
            OrganizationIntegrationSettings::TYPE_GOOGLE_WORKSPACE => $repository->createGoogleWorkspaceFromForm(
                $organization,
                $formData,
            ),
            OrganizationIntegrationSettings::TYPE_ACTION1 => $repository->createAction1FromForm(
                $organization,
                $formData,
            ),
            default => throw new InvalidArgumentException("Unsupported integration type [{$integrationType}]."),
        };

        return [
            'integration_id' => (string) $integration->getKey(),
            'organizational_settings' => is_array($organization->organizational_settings)
                ? $organization->organizational_settings
                : [],
            'integration' => $integration,
        ];
    }
}
