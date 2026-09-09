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

    // AI chatbot provider. 'gemini' | 'anthropic'. When unset, auto-detects:
    // Gemini if GEMINI_API_KEY is present, else Anthropic if ANTHROPIC_API_KEY is.
    'ai' => [
        'provider' => env('AI_PROVIDER'),
    ],

    'anthropic' => [
        'key'   => env('ANTHROPIC_API_KEY'),
        // Default to Anthropic's most capable model; override per deployment.
        // For a high-volume WhatsApp chatbot, claude-haiku-4-5 is cheaper/faster.
        'model' => env('ANTHROPIC_MODEL', 'claude-opus-5'),
    ],

    'gemini' => [
        'key'   => env('GEMINI_API_KEY'),
        // Fast, low-cost default; override with GEMINI_MODEL (e.g. gemini-1.5-flash).
        'model' => env('GEMINI_MODEL', 'gemini-2.0-flash'),
    ],

    'google' => [
        'client_id'     => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        // Where Google sends the user back — must exactly match the "Authorized
        // redirect URI" in the Google Cloud console, e.g.
        // https://main.heltog.com/api/v1/auth/google/callback
        // Falls back to APP_URL + the callback path so it stays consistent even
        // if GOOGLE_REDIRECT_URI is not set explicitly.
        'redirect'      => env('GOOGLE_REDIRECT_URI', rtrim((string) env('APP_URL', ''), '/') . '/api/v1/auth/google/callback'),
        // Where we send the user after issuing a token (the frontend app).
        'frontend_url'  => env('FRONTEND_URL', 'https://frontend.heltog.com'),
    ],
];
