<?php

return [

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

    // --- Africa's Talking SMS (Tanzania) ---
    'africastalking' => [
        'username' => env('AT_USERNAME'),
        'api_key' => env('AT_API_KEY'),
        'sender_id' => env('AT_SENDER_ID', 'SAFCO'),
        'environment' => env('AT_ENVIRONMENT', 'sandbox'),
    ],

    // --- Google OAuth (Socialite) ---
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID', 'dev-placeholder'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET', 'dev-placeholder'),
        'redirect' => env('GOOGLE_REDIRECT_URI', env('APP_URL').'/api/v1/auth/social/google/callback'),
    ],

    // --- Microsoft OAuth (SocialiteProviders\\MicrosoftAzure driver key = 'azure') ---
    'azure' => [
        'client_id' => env('MICROSOFT_CLIENT_ID', 'dev-placeholder'),
        'client_secret' => env('MICROSOFT_CLIENT_SECRET', 'dev-placeholder'),
        'redirect' => env('MICROSOFT_REDIRECT_URI', env('APP_URL').'/api/v1/auth/social/microsoft/callback'),
        'tenant' => env('MICROSOFT_TENANT_ID', 'common'),
    ],
    'microsoft' => [
        'client_id' => env('MICROSOFT_CLIENT_ID', 'dev-placeholder'),
        'client_secret' => env('MICROSOFT_CLIENT_SECRET', 'dev-placeholder'),
        'redirect' => env('MICROSOFT_REDIRECT_URI', env('APP_URL').'/api/v1/auth/social/microsoft/callback'),
        'tenant' => env('MICROSOFT_TENANT_ID', 'common'),
    ],

    'ai' => [
        'tutor_provider' => env('AI_TUTOR_PROVIDER', 'gemini'),
        'question_provider' => env('AI_QUESTION_PROVIDER', 'gemini'),
    ],

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-1.5-flash-latest'),
        'timeout' => (int) env('GEMINI_TIMEOUT', 30),
        'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
    ],

    'groq' => [
        'api_key' => env('GROQ_API_KEY'),
        'model' => env('GROQ_MODEL', 'llama-3.3-70b-versatile'),
        'base_url' => env('GROQ_BASE_URL', 'https://api.groq.com/openai/v1'),
    ],

    'openrouter' => [
        'api_key' => env('OPENROUTER_API_KEY'),
        'model' => env('OPENROUTER_MODEL', 'google/gemini-2.0-flash-exp:free'),
        'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
    ],

];
