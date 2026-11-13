<?php

return [
    // The original endpoint continues to use services.console_new and its existing env vars.
    'connections' => [
        'mydorris' => [
            'base_url' => env('CONSOLE_MYDORRIS_API_URL', 'https://console-new.mydorris.com/api/admin'),
            'token' => env('CONSOLE_MYDORRIS_API_TOKEN'),
            'timeout' => (int) env('CONSOLE_MYDORRIS_API_TIMEOUT', 15),
        ],
    ],

    'sites' => [
        'dressafford' => 'seawave',
        'dressafford.com' => 'seawave',
        'ellenbridals' => 'seawave',
        'ellenbridals.com' => 'seawave',
        'dorriswedding' => 'mydorris',
        'dorriswedding.com' => 'mydorris',
        'dorrisbridal' => 'mydorris',
        'dorrisbridal.com' => 'mydorris',
    ],

    // Existing prefixes confirmed in local records. Add new terminal prefixes after live verification.
    'order_prefixes' => [
        'dad' => 'seawave',
        'ebd' => 'seawave',
    ],
];
