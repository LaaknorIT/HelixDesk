<?php

namespace App\Support;

final class Action1SyncTarget
{
    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $credentials
     */
    public function __construct(
        public int $tenantId,
        public int $organizationId,
        public string $integrationId,
        public array $config,
        public array $credentials = [],
        public ?string $apiBaseUrl = null,
    ) {}
}
