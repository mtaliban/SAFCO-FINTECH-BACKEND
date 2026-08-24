<?php

namespace App\Services\Notifications;

use App\Models\NotificationDelivery;
use App\Models\User;
use App\Models\UserNotificationPref;
use App\Services\Notifications\Channels\ChannelContract;
use App\Services\Notifications\Channels\EmailChannel;
use App\Services\Notifications\Channels\InAppChannel;
use App\Services\Notifications\Channels\PushChannel;
use App\Services\Notifications\Channels\SmsChannel;
use App\Services\Notifications\Channels\WhatsAppChannel;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * SRS Module 15 — Central notification dispatcher.
 *
 * Flow for dispatch(User $user, string $eventKey, array $payload):
 *   1. If user is inactive → skip everything (recorded as skipped/inactive_user).
 *   2. Look up user's prefs for this event × each channel. Default = catalog default.
 *   3. Critical events (e.g. security_alert) IGNORE prefs — always sent.
 *   4. Rate limit check per (user, event, channel) — prevents flood.
 *   5. Call channel->send(); record status + preview + error in notification_deliveries.
 *   6. On hard exception, mark failed + schedule retry.
 *
 * Never throws — a broken channel doesn't take down the caller. Failures are
 * logged AND written to notification_deliveries so admins can debug.
 */
class NotificationDispatcher
{
    /** Rate: max N deliveries per user per event per channel per hour. */
    private const RATE_MAX = 6;
    private const RATE_WINDOW = 3600;

    /** Backoff for retries (in minutes). */
    private const RETRY_BACKOFFS = [5, 30, 180]; // 5m, 30m, 3h
    public const MAX_ATTEMPTS = 3;

    /** @var array<string, ChannelContract> */
    private array $channels;

    public function __construct(
        EmailChannel $email,
        InAppChannel $inApp,
        WhatsAppChannel $whatsapp,
        PushChannel $push,
        SmsChannel $sms,
    ) {
        $this->channels = [
            'email' => $email,
            'in_app' => $inApp,
            'whatsapp' => $whatsapp,
            'push' => $push,
            'sms' => $sms,
        ];
    }

    /**
     * Dispatch a single event to a single user across all their enabled channels.
     *
     * @return array<int, NotificationDelivery>  delivery records created
     */
    public function dispatch(User $user, string $eventKey, array $payload = []): array
    {
        $meta = EventCatalog::meta($eventKey);
        if (!$meta) {
            Log::warning('[notifications] unknown event_key', ['event_key' => $eventKey]);
            return [];
        }

        // Inactive users get NO notifications, even critical ones — their account
        // is disabled/suspended, so we don't ping them.
        if (($user->status ?? 'active') !== 'active') {
            return [$this->recordSkipped($user, $eventKey, '*', 'inactive_user', $payload)];
        }

        $critical = (bool) $meta['critical'];
        $records = [];

        foreach ($this->resolveChannels($user, $eventKey, $meta, $critical) as $channelKey => $enabled) {
            if (!$enabled) {
                // Silent skip when the user opted out — no delivery row (would flood the log).
                continue;
            }
            $records[] = $this->attemptChannel($user, $eventKey, $channelKey, $payload);
        }

        return $records;
    }

    /**
     * Dispatch the SAME event to many users. Used by admin broadcast.
     *
     * @param  iterable<User>  $users
     */
    public function dispatchMany(iterable $users, string $eventKey, array $payload = []): int
    {
        $sent = 0;
        foreach ($users as $u) {
            $recs = $this->dispatch($u, $eventKey, $payload);
            $sent += collect($recs)->where('status', 'sent')->count();
        }
        return $sent;
    }

    /**
     * Retry a previously failed delivery (called by the queue worker).
     */
    public function retry(NotificationDelivery $delivery): NotificationDelivery
    {
        if ($delivery->attempts >= self::MAX_ATTEMPTS) {
            return $delivery; // done trying
        }
        $user = User::find($delivery->user_id);
        if (!$user || ($user->status ?? 'active') !== 'active') {
            $delivery->update(['status' => 'skipped', 'skipped_reason' => 'inactive_user']);
            return $delivery;
        }
        $channel = $this->channels[$delivery->channel] ?? null;
        if (!$channel) {
            $delivery->update(['status' => 'failed', 'error_message' => 'unknown_channel']);
            return $delivery;
        }

        try {
            $result = $channel->send($user, $delivery->event_key, $delivery->payload ?? []);
            $delivery->update([
                'status' => $result['status'] === 'sent' ? 'sent' : 'skipped',
                'skipped_reason' => $result['reason'] ?? null,
                'subject' => $result['subject'] ?? $delivery->subject,
                'preview' => $result['preview'] ?? $delivery->preview,
                'sent_at' => $result['status'] === 'sent' ? now() : $delivery->sent_at,
                'attempts' => $delivery->attempts + 1,
                'next_retry_at' => null,
            ]);
        } catch (\Throwable $e) {
            $this->markFailedWithRetry($delivery, $e);
        }
        return $delivery->fresh();
    }

    // ─────────────────────────────────────────────────────────────

    private function attemptChannel(User $user, string $eventKey, string $channelKey, array $payload): NotificationDelivery
    {
        // Rate limit per (user, event, channel) — user can only get this exact
        // notification a few times per hour, avoiding flood loops.
        $rateKey = "notif:{$user->id}:{$eventKey}:{$channelKey}";
        if (RateLimiter::tooManyAttempts($rateKey, self::RATE_MAX)) {
            return $this->recordSkipped($user, $eventKey, $channelKey, 'rate_limited', $payload);
        }
        RateLimiter::hit($rateKey, self::RATE_WINDOW);

        $delivery = NotificationDelivery::create([
            'user_id' => $user->id,
            'event_key' => $eventKey,
            'channel' => $channelKey,
            'payload' => $payload,
            'status' => 'queued',
            'attempts' => 0,
        ]);

        $channel = $this->channels[$channelKey] ?? null;
        if (!$channel) {
            $delivery->update(['status' => 'failed', 'error_message' => 'unknown_channel']);
            return $delivery;
        }

        try {
            $result = $channel->send($user, $eventKey, $payload);
            if (($result['status'] ?? '') === 'sent') {
                $delivery->update([
                    'status' => 'sent',
                    'subject' => $result['subject'] ?? null,
                    'preview' => $result['preview'] ?? null,
                    'sent_at' => now(),
                    'attempts' => 1,
                ]);
            } else {
                $delivery->update([
                    'status' => 'skipped',
                    'skipped_reason' => $result['reason'] ?? 'unknown',
                    'attempts' => 1,
                ]);
            }
        } catch (\Throwable $e) {
            $this->markFailedWithRetry($delivery, $e);
        }
        return $delivery->fresh();
    }

    private function markFailedWithRetry(NotificationDelivery $d, \Throwable $e): void
    {
        $attempts = $d->attempts + 1;
        $next = null;
        if ($attempts < self::MAX_ATTEMPTS) {
            $backoffMinutes = self::RETRY_BACKOFFS[$attempts - 1] ?? 60;
            $next = now()->addMinutes($backoffMinutes);
        }
        $d->update([
            'status' => 'failed',
            'error_message' => \Str::limit($e->getMessage(), 500),
            'failed_at' => now(),
            'next_retry_at' => $next,
            'attempts' => $attempts,
        ]);
        Log::error('[notifications] channel_send_failed', [
            'delivery_uuid' => $d->uuid,
            'event' => $d->event_key,
            'channel' => $d->channel,
            'error' => $e->getMessage(),
        ]);
    }

    private function recordSkipped(User $user, string $eventKey, string $channelKey, string $reason, array $payload): NotificationDelivery
    {
        return NotificationDelivery::create([
            'user_id' => $user->id,
            'event_key' => $eventKey,
            'channel' => $channelKey,
            'payload' => $payload,
            'status' => 'skipped',
            'skipped_reason' => $reason,
            'attempts' => 0,
        ]);
    }

    /**
     * Build channelKey => enabled map for this user × event.
     * Only channels active at the app level are considered.
     */
    private function resolveChannels(User $user, string $eventKey, array $meta, bool $critical): array
    {
        $activeChannels = EventCatalog::activeChannels(); // ['email','in_app']
        $defaultChannels = $meta['default_channels'];

        // Look up any pref rows the user has for THIS event.
        $prefs = UserNotificationPref::where('user_id', $user->id)
            ->where('event_key', $eventKey)
            ->pluck('enabled', 'channel')
            ->toArray();

        $out = [];
        foreach ($activeChannels as $ch) {
            if ($critical) {
                // Security-critical items ignore prefs.
                $out[$ch] = true;
                continue;
            }
            if (array_key_exists($ch, $prefs)) {
                $out[$ch] = (bool) $prefs[$ch];
            } else {
                // No pref row → use catalog default.
                $out[$ch] = in_array($ch, $defaultChannels, true);
            }
        }
        return $out;
    }
}
