<?php

return [
    'return_policy_urls' => [
        'dressafford.com' => 'https://www.dressafford.com/article/return-policy.html',
        'dressafford' => 'https://www.dressafford.com/article/return-policy.html',
        'dorriswedding.com' => 'https://www.dorriswedding.com/article/return-policy.html',
        'dorriswedding' => 'https://www.dorriswedding.com/article/return-policy.html',
    ],
    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'base_url' => 'https://api.openai.com/v1',
        'model' => env('TICKET_AGENT_OPENAI_MODEL'),
    ],
    'timeout' => 600,
    'max_tool_calls' => 24,
    'pdftoppm_binary' => env('TICKET_AGENT_PDFTOPPM_BINARY', 'pdftoppm'),
    'tesseract_binary' => env('TICKET_AGENT_TESSERACT_BINARY', 'tesseract'),
    'max_document_bytes' => 25 * 1024 * 1024,
    'max_document_pages' => 4,
];
