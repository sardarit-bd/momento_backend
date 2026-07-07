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

    'resend' => [
        'key' => env('RESEND_KEY'),
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

    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],


    'tgc' => [
        'base_url' => env('TGC_BASE_URL', 'https://www.thegamecrafter.com/api'),
        'api_key_id' => env('TGC_API_KEY_ID', env('TGC_API_KEY')),
        'api_key' => env('TGC_API_KEY'),
        'private_key' => env('TGC_PRIVATE_KEY'),
        'username' => env('TGC_USERNAME'),
        'password' => env('TGC_PASSWORD'),
        'designer_id' => env('TGC_DESIGNER_ID'),
        'session_cache_ttl' => env('TGC_SESSION_CACHE_TTL_HOURS', 12),
        'webhook_callback_url' => env('TGC_WEBHOOK_CALLBACK_URL')
    ],

];
