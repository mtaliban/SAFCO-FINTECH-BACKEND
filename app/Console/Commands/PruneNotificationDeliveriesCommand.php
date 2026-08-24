<?php

namespace App\Console\Commands;

use App\Models\NotificationDelivery;
use Illuminate\Console\Command;

/**
 * SRS Module 15 — House-keeping for the delivery audit log.
 *
 * Keeps the last N days by default so the table doesn't grow without bound.
 * We retain FAILED rows longer so admins have more time to investigate.
 */
class PruneNotificationDeliveriesCommand extends Command
{
    protected $signature = 'notifications:prune {--sent-days=30} {--failed-days=90}';
    protected $description = 'Prune old notification_deliveries rows';

    public function handle(): int
    {
        $sentDays = (int) $this->option('sent-days');
        $failedDays = (int) $this->option('failed-days');

        $sent = NotificationDelivery::where('status', 'sent')
            ->where('created_at', '<', now()->subDays($sentDays))
            ->delete();
        $failed = NotificationDelivery::whereIn('status', ['failed', 'skipped'])
            ->where('created_at', '<', now()->subDays($failedDays))
            ->delete();

        $this->info("Pruned {$sent} sent + {$failed} failed/skipped rows.");
        return self::SUCCESS;
    }
}
