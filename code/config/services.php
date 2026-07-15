<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
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

    'python' => [
        'base_url' => env('PYTHON_SERVICE_URL', 'http://qforge_python:8000'),

        // Where the `shared` disk is mounted *inside the Python container*. Paths
        // sent to /extract are this root joined with document_uploads.stored_path.
        'shared_root' => env('PYTHON_SHARED_ROOT', '/shared-storage/shared'),

        // Extraction OCRs every scanned page; it is not a fast request.
        'extract_timeout' => (int) env('PYTHON_EXTRACT_TIMEOUT', 600),

        // AI generation calls a local LLM (CPU-only), so give it generous headroom.
        'generate_timeout' => (int) env('PYTHON_GENERATE_TIMEOUT', 300),
    ],

];
