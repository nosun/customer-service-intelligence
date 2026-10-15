<?php

return [

    'gmail' => [
        'gws_binary' => env('GWS_BINARY', 'gws'),
    ],

    'helpdesk' => [
        'account_id' => env('HELPDESK_ACCOUNT_ID'),
        'personal_access_token' => env('HELPDESK_PERSONAL_ACCESS_TOKEN'),
        'base_url' => env('HELPDESK_API_URL', 'https://api.helpdesk.com'),
        'current_agent_id' => env('HELPDESK_CURRENT_AGENT_ID'),
    ],

    'chatbot' => [
        'account_id' => env('CHATBOT_ACCOUNT_ID'),
        'access_token' => env('CHATBOT_DEVELOPER_ACCESS_TOKEN'),
        'base_url' => env('CHATBOT_API_URL', 'https://api.chatbot.com'),
    ],

    'console_new' => [
        'base_url' => env('CONSOLE_NEW_API_URL'),
        'token' => env('CONSOLE_NEW_API_TOKEN'),
        'timeout' => (int) env('CONSOLE_NEW_API_TIMEOUT', 15),
    ],

    'minimax' => [
        'api_key' => env('MINIMAX_API_KEY'),
        'base_url' => env('MINIMAX_API_URL', 'https://api.minimax.io/v1'),
        'model' => env('MINIMAX_MODEL', 'MiniMax-M3'),
        'timeout' => (int) env('MINIMAX_TIMEOUT', 180),
        'max_completion_tokens' => (int) env('MINIMAX_MAX_COMPLETION_TOKENS', 12000),
        'research_synthesis_ticket_chunk_size' => (int) env('MINIMAX_RESEARCH_SYNTHESIS_TICKET_CHUNK_SIZE', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
