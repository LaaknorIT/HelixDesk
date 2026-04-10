<?php

return [

    'entra' => [
        /**
         * Minimum hours since last successful Microsoft Entra sync before a new import is scheduled.
         */
        'import_interval_hours' => (int) env('ENTRA_IMPORT_INTERVAL_HOURS', 24),
    ],

    'monitoring' => [
        'deduplication_window_minutes' => (int) env('MONITORING_DEDUP_WINDOW_MINUTES', 15),

        /**
         * When an event is first ingested (not a deduplicated repeat), automatically create
         * an incident for these severity values (lowercase).
         */
        'auto_incident_severities' => array_map('strtolower', array_filter(array_map('trim', explode(',', (string) env(
            'MONITORING_AUTO_INCIDENT_SEVERITIES',
            'critical,high'
        ))))),
    ],

];
