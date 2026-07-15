<?php

namespace App\Support;

final class OrganizationIntegrationSyncTarget
{
    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $credentials
     */
    public function __construct(
        public int $tenantId,
        public int $organizationId,
        public string $integrationId,
        public string $integrationType,
        public array $config,
        public array $credentials = [],
        public ?string $apiBaseUrl = null,
    ) {}

    public static function fromEntraSyncTarget(EntraSyncTarget $target): self
    {
        return new self(
            tenantId: $target->tenantId,
            organizationId: $target->organizationId,
            integrationId: $target->integrationId,
            integrationType: IntegrationType::MICROSOFT_ENTRA,
            config: $target->config,
        );
    }

    public function toEntraSyncTarget(): EntraSyncTarget
    {
        return new EntraSyncTarget(
            tenantId: $this->tenantId,
            organizationId: $this->organizationId,
            integrationId: $this->integrationId,
            config: $this->config,
        );
    }
}
