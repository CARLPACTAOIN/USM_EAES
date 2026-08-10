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
        'mobile_client_ids' => array_filter(array_map(
            'trim',
            explode(',', (string) env('GOOGLE_MOBILE_CLIENT_IDS', ''))
        )),
    ],

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-1.5-pro'),
        'fallback_model' => env('GEMINI_FALLBACK_MODEL', 'gemini-2.0-flash'),
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
        'event_timezone' => env('EAES_EVENT_TIMEZONE', 'Asia/Manila'),
        'scan_early_checkin_buffer_min' => (int) env('EAES_SCAN_EARLY_CHECKIN_BUFFER_MIN', 60),
        'checkout_grace_period_min' => (int) env('EAES_CHECKOUT_GRACE_PERIOD_MIN', 60),
        'sync_grace_period_hours' => (int) env('EAES_SYNC_GRACE_PERIOD_HOURS', 72),
        'sentiment_queue_batch_size' => (int) env('EAES_SENTIMENT_QUEUE_BATCH_SIZE', 100),
        'redis_unavailable_retry_after' => (int) env('EAES_REDIS_UNAVAILABLE_RETRY_AFTER', 30),
        'queue_health' => [
            'max_queue_depth' => (int) env('EAES_QUEUE_HEALTH_MAX_QUEUE_DEPTH', 10000),
            'max_pending_outbox_entries' => (int) env('EAES_QUEUE_HEALTH_MAX_PENDING_OUTBOX_ENTRIES', 1000),
            'max_outbox_age_seconds' => (int) env('EAES_QUEUE_HEALTH_MAX_OUTBOX_AGE_SECONDS', 600),
            'max_recent_failed_jobs' => (int) env('EAES_QUEUE_HEALTH_MAX_RECENT_FAILED_JOBS', 0),
        ],
        'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),
        'mobile_deep_link_scheme' => env('EAES_MOBILE_DEEP_LINK_SCHEME', 'eaes'),
        'force_https' => (bool) env('EAES_FORCE_HTTPS', false),
        'security_headers' => (bool) env('EAES_SECURITY_HEADERS', true),
        'dev_login_enabled' => (bool) env('EAES_ENABLE_DEV_LOGIN', false),
        'queue_worker_managed' => (bool) env('EAES_QUEUE_WORKER_MANAGED', false),
        'scheduler_managed' => (bool) env('EAES_SCHEDULER_MANAGED', false),
        'rate_limits' => [
            'login_per_minute' => (int) env('EAES_RATE_LIMIT_LOGIN_PER_MINUTE', 5),
            'google_login_per_minute' => (int) env('EAES_RATE_LIMIT_GOOGLE_LOGIN_PER_MINUTE', 10),
            'authenticated_per_minute' => (int) env('EAES_RATE_LIMIT_AUTHENTICATED_PER_MINUTE', 120),
            'scanner_sync_per_minute' => (int) env('EAES_RATE_LIMIT_SCANNER_SYNC_PER_MINUTE', 30),
            'write_per_minute' => (int) env('EAES_RATE_LIMIT_WRITE_PER_MINUTE', 30),
            'nlp_per_minute' => (int) env('EAES_RATE_LIMIT_NLP_PER_MINUTE', 10),
        ],
    ],

];
