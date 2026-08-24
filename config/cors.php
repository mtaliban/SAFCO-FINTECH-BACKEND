<?php

/**
 * SRS Non-Functional Requirements — CORS whitelist.
 *
 * Origins are read from the CORS_ALLOWED_ORIGINS env (comma-separated).
 * We DO NOT allow "*" — that would let any site call the API from a browser.
 * `supports_credentials => true` requires exact-match origins (spec forbids "*").
 */

$fromEnv = array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))));

return [
    // API routes + Sanctum CSRF cookie for stateful (browser) auth
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    // Exact whitelist. Fallback to localhost dev if the env is unset so a
    // fresh checkout still works locally without config.
    'allowed_origins' => !empty($fromEnv) ? $fromEnv : [
        'http://localhost:3000',
        'http://127.0.0.1:3000',
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => [
        'Accept', 'Authorization', 'Content-Type', 'X-Requested-With',
        'X-Correlation-ID', 'X-CSRF-TOKEN', 'X-XSRF-TOKEN',
    ],

    // Expose these so the SPA can read them
    'exposed_headers' => ['X-Correlation-ID', 'X-RateLimit-Remaining', 'X-RateLimit-Limit'],

    'max_age' => 3600,

    // Bearer tokens don't require credentials, but Sanctum stateful sessions do.
    'supports_credentials' => true,
];
