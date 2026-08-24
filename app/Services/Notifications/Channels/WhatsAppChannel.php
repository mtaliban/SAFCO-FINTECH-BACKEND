<?php

namespace App\Services\Notifications\Channels;

use App\Models\User;

/**
 * SRS Module 15 — WhatsApp channel.
 *
 * Status: **infrastructure only**. We do NOT send outbound WhatsApp today
 * (would need a Business API provider). The dispatcher records the intent
 * as `status=skipped, reason=whatsapp_deferred` so we can later flip on the
 * actual sender in ONE place with no callers changing.
 *
 * The user-facing "click-to-chat" experience (wa.me links on trainer profiles)
 * is a *UI* feature that lives outside this dispatcher — it doesn't require
 * sending.
 */
class WhatsAppChannel implements ChannelContract
{
    public function key(): string { return 'whatsapp'; }

    public function send(User $user, string $eventKey, array $payload): array
    {
        return [
            'status' => 'skipped',
            'reason' => 'whatsapp_deferred',
        ];
    }
}
