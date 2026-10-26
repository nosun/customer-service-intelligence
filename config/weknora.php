<?php

return [
    'base_url' => env('WEKNORA_BASE_URL'),
    'read_key' => env('WEKNORA_READ_KEY'),
    'write_key' => env('WEKNORA_WRITE_KEY'),
    'publish_enabled' => env('WEKNORA_PUBLISH_ENABLED', false),
    'retrieval_enabled' => env('WEKNORA_RETRIEVAL_ENABLED', false),
    // Explicit site => KB mapping; use "global" for site-independent articles only.
    'knowledge_bases' => json_decode(env('WEKNORA_KNOWLEDGE_BASES', '{}'), true) ?: [],
];
