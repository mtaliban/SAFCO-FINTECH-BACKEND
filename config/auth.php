<?php

return [
    'defaults' => [
        'guard' => 'web',
        'passwords' => 'users',
    ],

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
        'api' => [
            'driver' => 'sanctum',
            'provider' => 'users',
        ],
    ],

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => App\Models\User::class,
        ],
    ],

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => 'password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => 10800,

    'otp' => [
        'length' => (int) env('OTP_LENGTH', 6),
        'expiry_minutes' => (int) env('OTP_EXPIRY_MINUTES', 5),
        'max_attempts' => (int) env('OTP_MAX_ATTEMPTS', 3),
    ],

    'two_factor' => [
        'issuer' => env('GOOGLE_2FA_ISSUER', 'SAFCO FINTECH LMS'),
        'window' => 1, // acceptable clock drift in 30-second slots
    ],
];
