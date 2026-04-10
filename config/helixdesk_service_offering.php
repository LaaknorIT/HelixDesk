<?php

/**
 * Service catalog: kinds, suggested pivot roles, and required JSON attribute keys per kind.
 *
 * @see docs/service-catalog.md
 */
return [

    'kinds' => [
        'internet_connectivity' => [
            'label' => 'Internet connectivity',
            'required_attributes' => ['line_number'],
        ],
        'business_application_suite' => [
            'label' => 'Business application suite',
            'required_attributes' => [],
        ],
        'custom' => [
            'label' => 'Custom',
            'required_attributes' => [],
        ],
    ],

    /**
     * Suggested asset pivot roles per kind (UI hints / validation allow-list when non-empty).
     *
     * @var array<string, list<string>>
     */
    'asset_roles_by_kind' => [
        'internet_connectivity' => ['primary_access', 'backup_access', 'circuit'],
        'business_application_suite' => ['financial_application', 'payroll_application'],
        'custom' => [],
    ],

    /**
     * Supplier pivot roles (all kinds).
     *
     * @var list<string>
     */
    'supplier_roles' => ['primary', 'backup', 'support'],

];
