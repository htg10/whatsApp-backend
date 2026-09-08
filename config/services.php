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

    'google' => [
        'client_id'     => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        // Where Google sends the user back — must exactly match the "Authorized
        // redirect URI" in the Google Cloud console, e.g.
        // https://main.heltog.com/api/v1/auth/google/callback
        'redirect'      => env('GOOGLE_REDIRECT_URI'),
        // Where we send the user after issuing a token (the frontend app).
        'frontend_url'  => env('FRONTEND_URL', 'https://frontend.heltog.com'),
    ],
];
