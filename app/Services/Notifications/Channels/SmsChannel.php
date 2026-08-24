<?php

namespace App\Services\Notifications\Channels;

use App\Models\User;

/**
 * SRS Module 15 — SMS channel.
 *
 * Status: **disabled by product decision**. Kept as a class so the plumbing
 * doesn't need surgery if we later enable a provider (Beem/AT).
 */
class SmsChannel implements ChannelContract
{
    public function key(): string { return 'sms'; }

    public function send(User $user, string $eventKey, array $payload): array
    {
        return ['status' => 'skipped', 'reason' => 'sms_disabled'];
    }
}
