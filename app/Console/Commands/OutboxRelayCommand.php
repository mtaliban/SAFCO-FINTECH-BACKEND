<?php

namespace App\Console\Commands;

use App\Jobs\PublishOutboxEventsJob;
use App\Models\EventOutbox;
use Illuminate\Console\Command;

/**
 * Scans the event_outbox for pending events and dispatches
 * a queue job for each one. Runs every minute via scheduler.
 */
class OutboxRelayCommand extends Command
{
    protected $signature = 'events:relay {--batch=200}';
    protected $description = 'Relay pending outbox events to message brokers';

    public function handle(): int
    {
        $batch = (int) $this->option('batch');

        $count = 0;
        EventOutbox::pending()
            ->orderBy('created_at')
            ->limit($batch)
            ->get()
            ->each(function (EventOutbox $outbox) use (&$count) {
                PublishOutboxEventsJob::dispatch($outbox->id);
                $count++;
            });

        $this->info("Dispatched {$count} outbox events to publisher queue.");

        return self::SUCCESS;
    }
}
