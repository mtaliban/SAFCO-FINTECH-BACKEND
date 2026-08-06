<?php

return [
    'host' => env('RABBITMQ_HOST', 'localhost'),
    'port' => (int) env('RABBITMQ_PORT', 5672),
    'user' => env('RABBITMQ_USER', 'guest'),
    'password' => env('RABBITMQ_PASSWORD', 'guest'),
    'vhost' => env('RABBITMQ_VHOST', '/'),
    'exchange' => env('RABBITMQ_EXCHANGE', 'safco.events'),
    'exchange_type' => env('RABBITMQ_EXCHANGE_TYPE', 'topic'),

    'queues' => [
        'user_events' => [
            'name' => 'safco.user.events',
            'routing_keys' => ['user.*'],
            'consumers' => ['UserEventsConsumer'],
        ],
        'quiz_events' => [
            'name' => 'safco.quiz.events',
            'routing_keys' => ['quiz.*', 'leaderboard.*'],
        ],
        'payment_events' => [
            'name' => 'safco.payment.events',
            'routing_keys' => ['payment.*'],
        ],
        'audit_events' => [
            'name' => 'safco.audit.all',
            'routing_keys' => ['#'], // capture EVERYTHING for audit log
        ],
    ],
];
