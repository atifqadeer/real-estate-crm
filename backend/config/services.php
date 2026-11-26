<?php

return [

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

    // Meta (Facebook Login). Credentials are NOT environment variables:
    // they live only in the database (platform_settings table), managed
    // by agency admins on the Platform Settings page and applied to this
    // config at boot (AppServiceProvider). Only non-secret operational
    // defaults remain here.
    'meta' => [
        'app_id' => null,
        'app_secret' => null,
        'redirect' => null,
        'graph_version' => 'v27.0',
        'scopes' => 'pages_show_list,pages_read_engagement,pages_manage_posts,pages_manage_metadata,instagram_basic,instagram_content_publish,leads_retrieval',
        'webhook_verify_token' => null,
        'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),
    ],

    // LinkedIn — same rule: credentials only in the database (Platform
    // Settings). The app must have the Community Management API product
    // approved for organization posting.
    'linkedin' => [
        'client_id' => null,
        'client_secret' => null,
        'redirect' => null,
        'version' => '202605',
        'scopes' => 'openid profile w_member_social r_organization_social w_organization_social',
        'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),
    ],

    // AI content assistant (any OpenAI-compatible endpoint) — the API
    // key lives only in the database (Platform Settings).
    'ai' => [
        'key' => null,
        'url' => 'https://api.openai.com/v1',
        'model' => 'gpt-4o-mini',
    ],

];
