<?php

namespace App\Console\Commands;

use App\Models\NotificationDelivery;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Console\Command;

/**
 * SRS Module 15 — Retry failed notification deliveries.
 *
 * Runs every 5 minutes. Picks up deliveries where:
 *   - status = 'failed'
 *   - attempts < MAX_ATTEMPTS
 *   - next_retry_at <= now
 *
 * The dispatcher's retry() handles the actual channel call and updates the
 * delivery row. If it fails again, next_retry_at is bumped with exponential
 * backoff (5m → 30m → 3h). After MAX_ATTEMPTS it stops.
 */
class RetryFailedNotificationsCommand extends Command
{
    protected $signature = 'notifications:retry {--limit=200}';
    protected $description = 'Retry failed notification deliveries with exponential backoff';

    public function handle(NotificationDispatcher $dispatcher): int
    {
        $limit = (int) $this->option('limit');

        $due = NotificationDelivery::where('status', 'failed')
            ->where('attempts', '<', NotificationDispatcher::MAX_ATTEMPTS)
            ->whereNotNull('next_retry_at')
            ->where('next_retry_at', '<=', now())
            ->orderBy('next_retry_at')
            ->limit($limit)
            ->get();

        if ($due->isEmpty()) {
            $this->info('No deliveries due for retry.');
            return self::SUCCESS;
        }

        $succeeded = 0;
        $stillFailing = 0;
        foreach ($due as $d) {
            $result = $dispatcher->retry($d);
            if ($result->status === 'sent') $succeeded++;
            else if ($result->status === 'failed') $stillFailing++;
        }

        $this->info("Retried {$due->count()} deliveries: {$succeeded} succeeded, {$stillFailing} still failing.");
        return self::SUCCESS;
    }
}
