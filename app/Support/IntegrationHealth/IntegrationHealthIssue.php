<?php

namespace App\Support\IntegrationHealth;

use App\Enums\IntegrationHealthSeverity;
use Illuminate\Support\Carbon;

/**
 * One problem found on an integration: either an error (sync is failing) or a
 * warning (sync works, but something needs attention soon).
 */
final readonly class IntegrationHealthIssue
{
    public function __construct(
        public string $code,
        public IntegrationHealthSeverity $severity,
        public string $title,
        public string $detail,
        public ?Carbon $occurredAt = null,
    ) {}

    public function isError(): bool
    {
        return $this->severity === IntegrationHealthSeverity::Error;
    }
}
