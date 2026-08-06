<?php

namespace App\Listeners\Security;

use App\Events\User\LoginFailed;
use App\Models\LoginHistory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Watches for brute-force patterns and unusual IPs.
 * If more than N failed attempts happen from a single IP within
 * a short window, we tag the IP for review by the security team.
 */
class DetectSuspiciousLogin implements ShouldQueue
{
    public string $queue = 'security';

    protected int $threshold = 10;
    protected int $windowMinutes = 15;

    public function handle(LoginFailed $event): void
    {
        if (! $event->ipAddress) {
            return;
        }

        $key = "login_failures:{$event->ipAddress}";
        $count = Cache::increment($key);
        Cache::put($key, $count, now()->addMinutes($this->windowMinutes));

        if ($count >= $this->threshold) {
            Log::channel('security')->warning('Possible brute-force attack detected', [
                'ip' => $event->ipAddress,
                'failure_count' => $count,
                'window_minutes' => $this->windowMinutes,
                'last_identifier' => $event->identifier,
            ]);

            Cache::put("blocked_ip:{$event->ipAddress}", true, now()->addHour());
        }
    }
}
