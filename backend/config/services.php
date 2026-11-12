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

    // Meta (Facebook Login) — Phase 2. Secrets live ONLY in .env / secret manager.
    'meta' => [
        'app_id' => env('META_APP_ID'),
        'app_secret' => env('META_APP_SECRET'),
        'redirect' => env('META_REDIRECT_URI'),
        'graph_version' => env('META_GRAPH_VERSION', 'v27.0'),
        'scopes' => env('META_SCOPES', 'pages_show_list,pages_read_engagement,pages_manage_posts,instagram_basic,instagram_content_publish,leads_retrieval'),
        'webhook_verify_token' => env('META_WEBHOOK_VERIFY_TOKEN'),
        'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),
    ],

    // LinkedIn (Phase 7) — app must have the Community Management API
    // product approved for organization posting.
    'linkedin' => [
        'client_id' => env('LINKEDIN_CLIENT_ID'),
        'client_secret' => env('LINKEDIN_CLIENT_SECRET'),
        'redirect' => env('LINKEDIN_REDIRECT_URI'),
        'version' => env('LINKEDIN_VERSION', '202605'),
        'scopes' => env('LINKEDIN_SCOPES', 'openid profile w_member_social r_organization_social w_organization_social'),
        'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),
    ],

    // AI content assistant (Phase 7) — any OpenAI-compatible endpoint.
    'ai' => [
        'key' => env('AI_API_KEY'),
        'url' => env('AI_API_URL', 'https://api.openai.com/v1'),
        'model' => env('AI_MODEL', 'gpt-4o-mini'),
    ],

];
