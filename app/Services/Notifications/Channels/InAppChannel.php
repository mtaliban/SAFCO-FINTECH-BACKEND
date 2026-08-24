<?php

namespace App\Services\Notifications\Channels;

use App\Models\User;
use App\Services\Notifications\Templates\NotificationTemplate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * SRS Module 15 — In-app inbox delivery.
 *
 * Writes to Laravel's notifications table (already provisioned in M14). The
 * frontend bell/inbox reads from this same table via the Notifiable trait, so
 * new items appear instantly with no extra plumbing.
 */
class InAppChannel implements ChannelContract
{
    public function key(): string { return 'in_app'; }

    public function send(User $user, string $eventKey, array $payload): array
    {
        $render = NotificationTemplate::render($eventKey, $user, $payload);

        DB::table('notifications')->insert([
            'id' => (string) Str::uuid(),
            'type' => 'App\\Notifications\\SafcoInboxNotification',
            'notifiable_type' => \App\Models\User::class,
            'notifiable_id' => $user->id,
            'data' => json_encode([
                'event_key' => $eventKey,
                'title' => $render['subject'],
                'body' => \Str::limit(strip_tags($render['html']), 300),
                'action_url' => $payload['action_url'] ?? null,
                'payload' => $payload,
            ]),
            'read_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'status' => 'sent',
            'subject' => $render['subject'],
            'preview' => \Str::limit(strip_tags($render['html']), 200),
        ];
    }
}
