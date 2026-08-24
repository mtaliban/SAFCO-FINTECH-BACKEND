<?php

namespace App\Services\Notifications\Channels;

use App\Models\User;

/**
 * SRS Module 15 — Mobile push channel.
 *
 * Status: **infrastructure only** (device_tokens table + this stub). Actual
 * FCM/APNs send happens when the mobile app ships. Recording as skipped
 * means the delivery log will show us who *would* have received a push
 * once wiring goes live.
 */
class PushChannel implements ChannelContract
{
    public function key(): string { return 'push'; }

    public function send(User $user, string $eventKey, array $payload): array
    {
        $tokens = \DB::table('device_tokens')->where('user_id', $user->id)->count();
        if ($tokens === 0) {
            return ['status' => 'skipped', 'reason' => 'no_device_tokens'];
        }
        return ['status' => 'skipped', 'reason' => 'push_deferred'];
    }
}
