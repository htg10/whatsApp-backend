<?php

return [
    'meta' => [
        'app_id'            => env('META_APP_ID'),
        'app_secret'        => env('META_APP_SECRET'),
        'verify_token'      => env('META_VERIFY_TOKEN', env('WHATSAPP_WEBHOOK_VERIFY_TOKEN')),
        'api_version'       => env('META_API_VERSION', 'v23.0'),
        'config_id'         => env('META_CONFIG_ID'),
        // System User token is the production credential. Encrypted at rest per WABA
        // in the DB; this env value is a fallback/bootstrap only.
        'system_user_token' => env('META_SYSTEM_USER_TOKEN'),
        'use_fake'          => env('META_USE_FAKE', false),
        'graph_base'        => env('META_GRAPH_BASE', 'https://graph.facebook.com'),
        'timeout'           => (int) env('META_HTTP_TIMEOUT', 30),
        'retries'           => (int) env('META_HTTP_RETRIES', 2),
        'retry_delay_ms'    => (int) env('META_HTTP_RETRY_DELAY', 300),
    ],

    'stripe' => [
        'key'            => env('STRIPE_KEY'),
        'secret'         => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

    'razorpay' => [
        'key'            => env('RAZORPAY_KEY'),
        'secret'         => env('RAZORPAY_SECRET'),
        'webhook_secret' => env('RAZORPAY_WEBHOOK_SECRET'),
    ],
];
