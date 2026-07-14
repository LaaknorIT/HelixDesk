<?php

namespace App\Support;

use App\Models\Organization;
use InvalidArgumentException;

final class OrganizationIntegrationProvisioner
{
    /**
     * @param  array<string, mixed>  $formData
     * @return array{integration_id: string, organizational_settings: array<string, mixed>}
     */
    public static function create(Organization $organization, string $integrationType, array $formData): array
    {
        $result = match ($integrationType) {
            OrganizationIntegrationSettings::TYPE_MICROSOFT_ENTRA => OrganizationIntegrationSettings::createMicrosoftEntraIntegration(
                $organization->organizational_settings,
                $formData,
            ),
            OrganizationIntegrationSettings::TYPE_GOOGLE_WORKSPACE => OrganizationIntegrationSettings::createGoogleWorkspaceIntegration(
                $organization->organizational_settings,
                $formData,
            ),
            default => throw new InvalidArgumentException("Unsupported integration type [{$integrationType}]."),
        };

        $organization->organizational_settings = $result['organizational_settings'];
        $organization->save();

        return $result;
    }
}
