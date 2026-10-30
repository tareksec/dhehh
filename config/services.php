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
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'meta' => [
        'graph_api_version' => env('META_GRAPH_API_VERSION', 'v25.0'),
        'max_media_bytes' => (int) env('META_MAX_MEDIA_BYTES', 26214400),
    ],

    // Social Content Planner (19_SOCIAL_CONTENT_PLANNER_PLAN.md): how long an Instagram media container is awaited.
    'social' => [
        'instagram_poll_attempts' => (int) env('SOCIAL_INSTAGRAM_POLL_ATTEMPTS', 10),
        'instagram_poll_seconds' => (int) env('SOCIAL_INSTAGRAM_POLL_SECONDS', 3),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
