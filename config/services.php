<?php

return [

    'nextsms' => [
        'enabled' => (bool) env('NEXTSMS_ENABLED', false),
        'token' => env('NEXTSMS_API_TOKEN'),
        'sender' => env('NEXT_SMS_SENDER_ID') ?: env('NEXTSMS_SENDER_ID'),
        'authorization' => env('NEXT_SMS_AUTHORIZATION'),
        'api_url' => env('NEXT_SMS_API_URL') ?: 'https://messaging-service.co.tz/api/sms/v2/text/single',
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
