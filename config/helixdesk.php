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

    'mail' => [
        'poll_interval_minutes' => (int) env('MAIL_POLL_INTERVAL_MINUTES', 5),
        'connection_timeout_seconds' => (int) env('MAIL_CONNECTION_TIMEOUT_SECONDS', 15),
        'attachment_disk' => env('MAIL_ATTACHMENT_DISK', 'local'),
        'attachment_path' => env('MAIL_ATTACHMENT_PATH', 'work-item-mail-attachments'),
        'work_item_public_id_length' => (int) env('MAIL_WORK_ITEM_PUBLIC_ID_LENGTH', 5),
        /**
         * Lowercase YouTube-like alphabet (no ambiguous 0/o/1/l). Lowercase-only
         * so plus-address tokens remain stable when addresses are normalized.
         */
        'work_item_public_id_alphabet' => env(
            'MAIL_WORK_ITEM_PUBLIC_ID_ALPHABET',
            'abcdefghijkmnpqrstuvwxyz23456789'
        ),
    ],

    'monitoring' => [
        'deduplication_window_minutes' => (int) env('MONITORING_DEDUP_WINDOW_MINUTES', 15),

        /**
         * When an event is first ingested (not a deduplicated repeat), automatically create
         * a work item for these severity values (lowercase).
         */
        'auto_work_item_severities' => array_map('strtolower', array_filter(array_map('trim', explode(',', (string) env(
            'MONITORING_AUTO_INCIDENT_SEVERITIES',
            'critical,high'
        ))))),
    ],

];
