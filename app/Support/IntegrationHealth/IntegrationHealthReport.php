<?php

namespace App\Support\IntegrationHealth;

use App\Enums\IntegrationHealthSeverity;
use App\Enums\IntegrationHealthStatus;
use App\Models\OrganizationIntegration;

final readonly class IntegrationHealthReport
{
    /**
     * @param  list<IntegrationHealthIssue>  $issues
     */
    public function __construct(
        public OrganizationIntegration $integration,
        public IntegrationHealthStatus $status,
        public array $issues,
    ) {}

    /**
     * @return list<IntegrationHealthIssue>
     */
    public function errors(): array
    {
        return array_values(array_filter(
            $this->issues,
            fn (IntegrationHealthIssue $issue): bool => $issue->severity === IntegrationHealthSeverity::Error,
        ));
    }

    /**
     * @return list<IntegrationHealthIssue>
     */
    public function warnings(): array
    {
        return array_values(array_filter(
            $this->issues,
            fn (IntegrationHealthIssue $issue): bool => $issue->severity === IntegrationHealthSeverity::Warning,
        ));
    }

    public function firstIssue(): ?IntegrationHealthIssue
    {
        return $this->errors()[0] ?? $this->warnings()[0] ?? null;
    }
}
