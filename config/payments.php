<?php

return [
    /**
     * Global mock switch. When true, providers accept payments and let the
     * webhook flow complete without calling any real API. Flip to false and
     * fill in per-provider credentials for live mode.
     */
    'mock_mode' => env('PAYMENTS_MOCK_MODE', true),

    /** Standard Tanzania VAT (18%). */
    'vat_percent' => (int) env('PAYMENTS_VAT_PERCENT', 18),

    /** Invoice number sequence prefix — appears in generated PDFs. */
    'invoice_prefix' => env('PAYMENTS_INVOICE_PREFIX', 'SAFCO-INV'),

    /** SAFCO issuer identity for the printed invoice/receipt. */
    'issuer' => [
        'name' => env('PAYMENTS_ISSUER_NAME', 'SAFCO FINTECH LIMITED'),
        'tin' => env('PAYMENTS_ISSUER_TIN', '000-000-000'),
        'address' => env('PAYMENTS_ISSUER_ADDRESS', 'Dar es Salaam, Tanzania'),
        'phone' => env('PAYMENTS_ISSUER_PHONE', '+255 000 000 000'),
        'email' => env('PAYMENTS_ISSUER_EMAIL', 'billing@safcofintech.co.tz'),
    ],

    /** Per-provider config — mostly credentials for live mode. */
    'providers' => [
        'mpesa' => [
            'consumer_key' => env('MPESA_CONSUMER_KEY'),
            'consumer_secret' => env('MPESA_CONSUMER_SECRET'),
            'business_short_code' => env('MPESA_BUSINESS_SHORT_CODE'),
            'passkey' => env('MPESA_PASSKEY'),
            'webhook_secret' => env('MPESA_WEBHOOK_SECRET'),
        ],
        'mixx' => [
            'api_key' => env('MIXX_API_KEY'),
            'api_secret' => env('MIXX_API_SECRET'),
            'webhook_secret' => env('MIXX_WEBHOOK_SECRET'),
        ],
        'airtel_money' => [
            'client_id' => env('AIRTEL_CLIENT_ID'),
            'client_secret' => env('AIRTEL_CLIENT_SECRET'),
            'webhook_secret' => env('AIRTEL_WEBHOOK_SECRET'),
        ],
        'crdb' => [
            'merchant_id' => env('CRDB_MERCHANT_ID'),
            'api_key' => env('CRDB_API_KEY'),
            'webhook_secret' => env('CRDB_WEBHOOK_SECRET'),
        ],
        'nmb' => [
            'merchant_id' => env('NMB_MERCHANT_ID'),
            'api_key' => env('NMB_API_KEY'),
            'webhook_secret' => env('NMB_WEBHOOK_SECRET'),
        ],
        'nbc' => [
            'merchant_id' => env('NBC_MERCHANT_ID'),
            'api_key' => env('NBC_API_KEY'),
            'webhook_secret' => env('NBC_WEBHOOK_SECRET'),
        ],
        'card_visa' => [
            'gateway' => env('CARD_GATEWAY', 'dpo'),
            'merchant_id' => env('CARD_MERCHANT_ID'),
            'api_key' => env('CARD_API_KEY'),
            'webhook_secret' => env('CARD_WEBHOOK_SECRET'),
        ],
        'card_mastercard' => [
            'gateway' => env('CARD_GATEWAY', 'dpo'),
            'merchant_id' => env('CARD_MERCHANT_ID'),
            'api_key' => env('CARD_API_KEY'),
            'webhook_secret' => env('CARD_WEBHOOK_SECRET'),
        ],
    ],
];
