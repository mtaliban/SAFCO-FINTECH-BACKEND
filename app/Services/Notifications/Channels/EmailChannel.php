<?php

namespace App\Services\Notifications\Channels;

use App\Models\User;
use App\Services\Notifications\Templates\NotificationTemplate;
use Illuminate\Support\Facades\Mail;

/**
 * SRS Module 15 — Email delivery via Laravel Mail (Gmail SMTP in .env).
 */
class EmailChannel implements ChannelContract
{
    public function key(): string { return 'email'; }

    public function send(User $user, string $eventKey, array $payload): array
    {
        if (!$user->email) {
            return ['status' => 'skipped', 'reason' => 'no_email_on_file'];
        }
        $render = NotificationTemplate::render($eventKey, $user, $payload);

        Mail::html($render['html'], function ($m) use ($user, $render) {
            $m->to($user->email, $user->profile?->full_name ?? null)
              ->subject($render['subject']);
        });

        return [
            'status' => 'sent',
            'subject' => $render['subject'],
            'preview' => \Str::limit(strip_tags($render['html']), 200),
        ];
    }
}
