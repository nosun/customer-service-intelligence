<?php

return [
    'mode' => env('CUSTOMER_CONTACT_MODE', 'shadow'),
    'send_enabled' => (bool) env('CUSTOMER_CONTACT_SEND_ENABLED', false),
    'console_note_enabled' => (bool) env('CUSTOMER_CONTACT_NOTE_ENABLED', false),
    'timeout_execute_enabled' => false, // The deployed release API does not meet the timeout contract.
    'scan_enabled' => (bool) env('CUSTOMER_CONTACT_SCAN_ENABLED', false),
    'workflow_version' => '1.0.0',
    'workflow_path' => '/webhook/multi-dress-first-round-v1',
    'page_limit' => 100,
    'communication_fresh_seconds' => 60,
    'sites' => [
        'dressafford' => ['sid' => 35, 'prefix' => 'dad', 'brand' => 'DressAfford', 'send_enabled' => (bool) env('CUSTOMER_CONTACT_DAD_SEND_ENABLED', false),
            'policy_verified' => (bool) env('CUSTOMER_CONTACT_DAD_POLICY_VERIFIED', false), 'policy_version' => '2026-11-11', 'policy_url' => 'https://www.dressafford.com/article/return-policy.html'],
        'ellenbridals' => ['sid' => 36, 'prefix' => 'ebd', 'brand' => 'EllenBridals', 'send_enabled' => (bool) env('CUSTOMER_CONTACT_EBD_SEND_ENABLED', false),
            'policy_verified' => (bool) env('CUSTOMER_CONTACT_EBD_POLICY_VERIFIED', false), 'policy_version' => '2026-11-11', 'policy_url' => 'https://www.ellenbridals.com/article/return-policy.html'],
    ],
];
