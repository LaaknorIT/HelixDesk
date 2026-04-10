<?php

namespace App\Support;

final class EntraSyncTarget
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        public int $tenantId,
        public int $organizationId,
        public string $integrationId,
        public array $config,
    ) {}
}
