<?php

return [
    'host' => env('MQTT_HOST', 'localhost'),
    'port' => (int) env('MQTT_PORT', 1883),
    'username' => env('MQTT_USERNAME'),
    'password' => env('MQTT_PASSWORD'),
    'client_id' => env('MQTT_CLIENT_ID', 'safco_lms_backend'),
    'keep_alive' => (int) env('MQTT_KEEP_ALIVE', 60),
    'clean_session' => (bool) env('MQTT_CLEAN_SESSION', true),
    'tls_enabled' => (bool) env('MQTT_TLS_ENABLED', false),
    'topic_prefix' => env('MQTT_TOPIC_PREFIX', 'safco/lms'),

    'topics' => [
        'user_events' => 'safco/lms/user/#',
        'quiz_events' => 'safco/lms/quiz/#',
        'leaderboard' => 'safco/lms/leaderboard/{sessionPin}',
        'notifications' => 'safco/lms/notifications/{userId}',
    ],
];
