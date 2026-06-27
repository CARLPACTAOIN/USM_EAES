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

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    'gemini' => [
        'api_key'         => env('GEMINI_API_KEY'),
        'model'           => env('GEMINI_MODEL', 'gemini-1.5-pro'),
        'fallback_model'  => env('GEMINI_FALLBACK_MODEL', 'gemini-2.0-flash'),
        'request_timeout' => (int) env('GEMINI_REQUEST_TIMEOUT', 60),
    ],

    'ollama' => [
        'api_url' => env('OLLAMA_API_URL', 'https://ollama.com'),
        'api_key' => env('OLLAMA_API_KEY'),
        'model' => env('OLLAMA_MODEL', 'gpt-oss:20b-cloud'),
    ],

    'ai' => [
        'provider' => env('AI_PROVIDER', 'gemini'), // 'gemini' or 'ollama'
    ],

    'eaes' => [
        'allowed_email_domain' => env('EAES_ALLOWED_EMAIL_DOMAIN', 'usm.edu.ph'),
        'university_name' => env('EAES_UNIVERSITY_NAME', 'University of Southern Mindanao'),
        'sync_batch_size' => (int) env('EAES_SYNC_BATCH_SIZE', 200),
        'sync_chunk_size' => (int) env('EAES_SYNC_CHUNK_SIZE', 200),
        'evaluation_window_hours' => (int) env('EAES_EVALUATION_WINDOW_HOURS', 24),
        'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),
        'mobile_deep_link_scheme' => env('EAES_MOBILE_DEEP_LINK_SCHEME', 'eaes'),
        'force_https' => (bool) env('EAES_FORCE_HTTPS', false),
        'security_headers' => (bool) env('EAES_SECURITY_HEADERS', true),
        'dev_login_enabled' => (bool) env('EAES_ENABLE_DEV_LOGIN', false),
    ],

];
