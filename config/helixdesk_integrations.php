<?php

return [
    'entra' => [
        'import_interval_hours' => (int) env('ENTRA_IMPORT_INTERVAL_HOURS', 24),
        'sync_job_timeout_seconds' => (int) env('ENTRA_SYNC_JOB_TIMEOUT_SECONDS', 600),
    ],

    'action1' => [
        'import_interval_hours' => (int) env('ACTION1_IMPORT_INTERVAL_HOURS', 6),
        'default_api_base_url' => 'https://app.action1.com/api/3.0/',
        'regions' => [
            'us' => 'https://app.action1.com/api/3.0/',
            'eu' => 'https://app.eu.action1.com/api/3.0/',
        ],
        'http' => [
            'connect_timeout_seconds' => (int) env('ACTION1_HTTP_CONNECT_TIMEOUT_SECONDS', 10),
            'auth_timeout_seconds' => (int) env('ACTION1_HTTP_AUTH_TIMEOUT_SECONDS', 30),
            'request_timeout_seconds' => (int) env('ACTION1_HTTP_REQUEST_TIMEOUT_SECONDS', 120),
            'retry_times' => (int) env('ACTION1_HTTP_RETRY_TIMES', 2),
            'retry_sleep_milliseconds' => (int) env('ACTION1_HTTP_RETRY_SLEEP_MILLISECONDS', 500),
        ],
        'sync_job_timeout_seconds' => (int) env('ACTION1_SYNC_JOB_TIMEOUT_SECONDS', 600),
    ],

    'asset_reconciliation_enabled' => env('HELIXDESK_ASSET_RECONCILIATION', false),

    'source_of_truth' => [
        'name' => ['action1', 'microsoft_entra'],
        'serial_number' => ['action1', 'microsoft_entra'],
        'primary_hostname' => ['action1', 'microsoft_entra'],
        'operating_system' => ['action1', 'microsoft_entra'],
        'owner_user_id' => ['microsoft_entra', 'action1'],
        'lifecycle_status' => ['helixdesk', 'action1', 'microsoft_entra'],
        'asset_identifier' => ['helixdesk'],
        'asset_type_id' => ['helixdesk', 'action1', 'microsoft_entra'],
    ],

    'identity' => [
        'serial_blocklist' => ['0', 'Default string', 'To be filled by O.E.M.'],
        'retirement_grace_days' => (int) env('HELIXDESK_RETIREMENT_GRACE_DAYS', 30),
    ],
];
