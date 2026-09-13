<?php

return [

    'agent' => [
        'url' => env('AGENT_SERVICE_URL', 'http://127.0.0.1:8005'),
        'secret' => env('AGENT_SERVICE_SECRET', 'local-demo-secret'),
        'demo_mode' => env('DEMO_MODE', false),
    ],

    'hubspot' => [
        'access_token' => env('HUBSPOT_ACCESS_TOKEN'),
        'portal_id' => env('HUBSPOT_PORTAL_ID'),
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
