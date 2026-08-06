<?php

namespace App\Jobs;

use App\Events\BaseEvent;
use App\Models\EventOutbox;
use App\Services\EventBus\EventDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Reads pending rows from event_outbox and forwards them to
 * RabbitMQ + MQTT (HiveMQ). Runs continuously via `php artisan queue:work`.
 *
 * This is the "publisher" side of the Transactional Outbox pattern —
 * it turns rows in the outbox into real broker messages, retrying on
 * failure and giving up (marks failed) after 5 attempts.
 */
class PublishOutboxEventsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public string $queue = 'event-bus';

    public function __construct(public int $outboxId)
    {
    }

    public function handle(EventDispatcher $dispatcher): void
    {
        $outbox = EventOutbox::find($this->outboxId);

        if (! $outbox || $outbox->status === 'published') {
            return;
        }

        $envelope = $outbox->payload;
        $event = new class ($envelope) extends BaseEvent {
            public function __construct(protected array $envelope)
            {
                $this->eventId = $envelope['event_id'];
                $this->eventName = $envelope['event_name'];
                $this->occurredAt = $envelope['occurred_at'];
                $this->correlationId = $envelope['correlation_id'];
            }

            public static function eventName(): string { return 'generic'; }
            public function toPayload(): array { return $this->envelope['payload'] ?? []; }
            public function envelope(): array { return $this->envelope; }
        };

        $dispatcher->publishOutbox($outbox, $event);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('Outbox publisher failed permanently', [
            'outbox_id' => $this->outboxId,
            'error' => $e->getMessage(),
        ]);
    }
}
