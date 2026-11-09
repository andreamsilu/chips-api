<?php

return [
    'delivery_base_fee' => (int) env('DELIVERY_BASE_FEE', 2000),
    'delivery_per_km' => (int) env('DELIVERY_PER_KM', 500),
    'gateway_url' => env('SNIPPE_BASE_URL', 'https://api.snippe.sh'),
    'gateway_token' => env('SNIPPE_API_KEY'),
    'webhook_secret' => env('SNIPPE_WEBHOOK_SECRET'),
    'webhook_url' => env('SNIPPE_WEBHOOK_URL'),
    'payouts_enabled' => (bool) env('SNIPPE_PAYOUTS_ENABLED', false),
    'webhook_tolerance' => 300,
    'token_expiry_minutes' => (int) env('TOKEN_EXPIRY_MINUTES', 10080),
];
