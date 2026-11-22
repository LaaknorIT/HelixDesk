<?php

namespace App\Support\DeviceCoverage;

use App\Enums\DeviceClass;

final class DeviceCoverageExport
{
    /**
     * @param  list<array<string, mixed>>|null  $rows  defaults to all rows of the report
     */
    public static function toCsv(DeviceCoverageReport $report, ?array $rows = null): string
    {
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, [
            __('Device'),
            __('Organization'),
            __('Class'),
            __('Operating system'),
            ...array_values($report->sources),
            __('Findings'),
        ]);

        foreach ($rows ?? $report->rows as $row) {
            $cells = [];
            foreach (array_keys($report->sources) as $key) {
                $cells[] = $row['cells'][$key] ?? DeviceCoverageReport::CELL_NOT_ENABLED;
            }

            fputcsv($handle, [
                $row['name'],
                $row['organization'],
                DeviceClass::from($row['device_class'])->label(),
                $row['operating_system'],
                ...$cells,
                implode('; ', array_map(
                    fn (string $ruleKey): string => CoverageRules::find($ruleKey)->name ?? $ruleKey,
                    $row['findings'],
                )),
            ]);
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}
