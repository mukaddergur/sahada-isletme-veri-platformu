<?php

return [


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

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'google' => [
        'places_api_key' => env('GOOGLE_PLACES_API_KEY'),
    ],

    'crawler' => [
        'url' => env('CRAWLER_SERVICE_URL'),

        'allow_demo_fallback' => (bool) env('MAPLEAD_ALLOW_DEMO_FALLBACK', false),

        'prefer_osm' => (bool) env('MAPLEAD_PREFER_OSM', true),
    ],

    'ai' => [
        'provider' => env('AI_PROVIDER', 'local'),
        'openai_key' => env('OPENAI_API_KEY'),
    ],

];
