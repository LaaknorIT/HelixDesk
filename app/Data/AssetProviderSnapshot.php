<?php

namespace App\Data;

use App\Enums\AssetExternalIdentifierType;

final class AssetProviderSnapshot
{
    /**
     * @param  list<array{type: AssetExternalIdentifierType, value: string}>  $identifiers
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $satellite
     */
    public function __construct(
        public int $tenantId,
        public int $organizationId,
        public string $organizationIntegrationId,
        public string $provider,
        public array $identifiers,
        public array $attributes = [],
        public array $satellite = [],
    ) {}

    public function identifierValue(AssetExternalIdentifierType $type): ?string
    {
        foreach ($this->identifiers as $identifier) {
            if ($identifier['type'] === $type) {
                $value = trim((string) ($identifier['value'] ?? ''));

                return $value !== '' ? $value : null;
            }
        }

        return null;
    }

    /**
     * @return list<array{type: AssetExternalIdentifierType, value: string}>
     */
    public function hardMatchIdentifiers(): array
    {
        return array_values(array_filter(
            $this->identifiers,
            fn (array $identifier): bool => $identifier['type']->isHardMatchType()
                && trim((string) ($identifier['value'] ?? '')) !== '',
        ));
    }
}
