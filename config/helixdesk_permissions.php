<?php

/**
 * Hierarchical permissions: `{module}.{action}`.
 *
 * - `modules.*.actions` defines which cells exist for that row in the role matrix UI.
 * - Seeding uses `tenant_roles.*.permissions`: `*` or a list of explicit names and/or `module.*` wildcards.
 */
return [

    'action_labels' => [
        'read' => 'Read',
        'create' => 'Create',
        'update' => 'Update',
        'delete' => 'Delete',
        'approve' => 'Approve',
        'admin' => 'Admin',
    ],

    'modules' => [
        'incident' => [
            'label' => 'Incidents',
            'actions' => ['read', 'create', 'update', 'delete', 'admin'],
        ],
        'problem' => [
            'label' => 'Problems',
            'actions' => ['read', 'create', 'update', 'delete', 'admin'],
        ],
        'change' => [
            'label' => 'Changes',
            'actions' => ['read', 'create', 'update', 'delete', 'approve', 'admin'],
        ],
        'asset' => [
            'label' => 'Assets',
            'actions' => ['read', 'create', 'update', 'delete', 'admin'],
        ],
        'document' => [
            'label' => 'Documentation',
            'actions' => ['read', 'create', 'update', 'delete', 'admin'],
        ],
        'monitoring_event' => [
            'label' => 'Monitoring events',
            'actions' => ['read', 'create', 'update', 'delete', 'admin'],
        ],
        'dashboard' => [
            'label' => 'Dashboards',
            'actions' => ['read', 'update', 'admin'],
        ],
        'tenant' => [
            'label' => 'Roles & access',
            'actions' => ['read', 'admin'],
        ],
    ],

    'tenant_roles' => [
        'tenant_admin' => [
            'name' => 'Tenant Admin',
            'permissions' => '*',
        ],
        'agent' => [
            'name' => 'Agent',
            'permissions' => [
                'incident.read',
                'incident.create',
                'incident.update',
                'problem.read',
                'problem.create',
                'problem.update',
                'change.read',
                'change.create',
                'change.update',
                'asset.read',
                'asset.create',
                'asset.update',
                'document.read',
                'document.create',
                'document.update',
                'monitoring_event.read',
                'monitoring_event.create',
                'monitoring_event.update',
                'dashboard.read',
            ],
        ],
    ],

];
