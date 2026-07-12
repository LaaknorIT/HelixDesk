<?php

return [

    'demo_logins' => [
        'password' => 'password',
        'tenant_slug' => 'demo-tenant',
        'accounts' => [
            [
                'email' => 'demo-globaladmin@helixdesk.local',
                'label' => 'Demo Global Admin',
                'panels' => ['global-admin'],
            ],
            [
                'email' => 'demo-agent@helixdesk.local',
                'label' => 'Demo Agent',
                'panels' => ['agent'],
            ],
            [
                'email' => 'demo-tenantadmin@helixdesk.local',
                'label' => 'Demo Tenant Admin',
                'panels' => ['agent'],
            ],
            [
                'email' => 'demo-enduser1@helixdesk.local',
                'label' => 'Demo End User 1',
                'panels' => ['end-user'],
            ],
            [
                'email' => 'demo-enduser2@helixdesk.local',
                'label' => 'Demo End User 2',
                'panels' => ['end-user'],
            ],
        ],
    ],

    'organizations' => [
        'navigation_group' => env('HELIXDESK_ORG_NAV_GROUP', 'Customers'),
        'navigation_label' => env('HELIXDESK_ORG_NAV_LABEL', 'Customers'),
        'model_label' => env('HELIXDESK_ORG_MODEL_LABEL', 'Customer'),
        'plural_model_label' => env('HELIXDESK_ORG_PLURAL_MODEL_LABEL', 'Customers'),
    ],

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
