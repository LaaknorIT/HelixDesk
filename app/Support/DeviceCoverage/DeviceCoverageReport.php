<?php

namespace App\Support\DeviceCoverage;

/**
 * The result of comparing device sources for one tenant (optionally one organization).
 */
final readonly class DeviceCoverageReport
{
    public const CELL_ACTIVE = 'active';

    public const CELL_STALE = 'stale';

    public const CELL_DISABLED = 'disabled';

    /** Expected by a rule but not reported by the source. */
    public const CELL_MISSING = 'missing';

    /** Not reported, and no rule expects it. */
    public const CELL_ABSENT = 'absent';

    /** The source is not enabled for the device's organization. */
    public const CELL_NOT_ENABLED = 'not_enabled';

    /**
     * @param  array<string, string>  $sources  source key => label, in column order
     * @param  list<array{rule: CoverageRule, in_scope: int, missing: int}>  $ruleSummaries
     * @param  list<array{
     *     key: string,
     *     organization_id: int,
     *     organization: string,
     *     asset_id: ?int,
     *     name: string,
     *     device_class: string,
     *     operating_system: ?string,
     *     probable_match: bool,
     *     cells: array<string, string>,
     *     last_activity: array<string, ?string>,
     *     findings: list<string>,
     * }>  $rows
     */
    public function __construct(
        public array $sources,
        public array $ruleSummaries,
        public array $rows,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function rowsWithFindings(): array
    {
        return array_values(array_filter($this->rows, fn (array $row): bool => $row['findings'] !== []));
    }

    public function missingCount(string $ruleKey): int
    {
        foreach ($this->ruleSummaries as $summary) {
            if ($summary['rule']->key === $ruleKey) {
                return $summary['missing'];
            }
        }

        return 0;
    }
}
