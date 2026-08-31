<?php

namespace App\Services\Notifications\Channels;

use App\Events\InAppNotificationSent;
use App\Models\User;
use App\Services\Notifications\Templates\NotificationTemplate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * SRS Module 15 — In-app inbox delivery.
 *
 * Writes to Laravel's notifications table, then publishes a lightweight MQTT
 * ping to safco/lms/notifications/{userId} so the frontend bell badge updates
 * in real time without any polling delay.
 */
class InAppChannel implements ChannelContract
{
    public function __construct() {}

    public function key(): string { return 'in_app'; }

    public function send(User $user, string $eventKey, array $payload): array
    {
        $render = NotificationTemplate::render($eventKey, $user, $payload);

        $id = (string) Str::uuid();

        DB::table('notifications')->insert([
            'id'              => $id,
            'type'            => 'App\\Notifications\\SafcoInboxNotification',
            'notifiable_type' => \App\Models\User::class,
            'notifiable_id'   => $user->id,
            'data'            => json_encode([
                'event_key'  => $eventKey,
                'title'      => $render['subject'],
                'body'       => \Str::limit(strip_tags($render['html']), 300),
                'action_url' => $payload['action_url'] ?? null,
                'payload'    => $payload,
            ]),
            'read_at'    => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Real-time push via Laravel Reverb WebSocket.
        // ShouldBroadcastNow means it fires immediately (not queued).
        // Failure is non-fatal — the DB record already exists and the 15s
        // polling fallback will surface the notification regardless.
        try {
            broadcast(new InAppNotificationSent(
                userId:    $user->id,
                id:        $id,
                eventKey:  $eventKey,
                title:     $render['subject'],
                body:      \Str::limit(strip_tags($render['html']), 200),
                actionUrl: $payload['action_url'] ?? null,
            ));
        } catch (\Throwable $e) {
            Log::warning('[notifications] Reverb broadcast failed — falling back to poll', [
                'user_id'   => $user->id,
                'event_key' => $eventKey,
                'error'     => $e->getMessage(),
            ]);
        }

        return [
            'status'  => 'sent',
            'subject' => $render['subject'],
            'preview' => \Str::limit(strip_tags($render['html']), 200),
        ];
    }
}
