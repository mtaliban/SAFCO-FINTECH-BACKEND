<?php

namespace App\Services\EventBus;

use App\Events\BaseEvent;
use App\Models\EventOutbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The main entry point for publishing domain events.
 *
 * Uses the Transactional Outbox pattern:
 *   1. Save the event to `event_outbox` in the same DB transaction as
 *      the business change (guaranteeing atomicity).
 *   2. A worker (PublishOutboxEventsJob) reads unpublished rows and
 *      forwards them to RabbitMQ and/or MQTT, retrying on failure.
 *
 * This avoids the "dual-write" problem where the DB commits but the
 * broker publish fails (or vice versa), which is a classic source of
 * inconsistency in event-driven systems.
 */
class EventDispatcher
{
    public function __construct(
        protected RabbitMqPublisher $rabbit,
        protected MqttPublisher $mqtt,
    ) {
    }

    /**
     * Persist the event to the outbox for reliable delivery.
     */
    public function dispatch(BaseEvent $event, ?string $aggregateType = null, ?int $aggregateId = null): void
    {
        try {
            EventOutbox::create([
                'event_id' => $event->eventId,
                'event_name' => $event->eventName,
                'aggregate_type' => $aggregateType,
                'aggregate_id' => $aggregateId,
                'payload' => $event->envelope(),
                'routing_key' => $event->routingKey(),
                'broker' => $event->broker(),
                'status' => 'pending',
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to enqueue event in outbox', [
                'event' => $event->eventName,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Publish an already-persisted outbox row to its target broker(s).
     */
    public function publishOutbox(EventOutbox $outbox, BaseEvent $event): bool
    {
        $success = true;

        if (in_array($outbox->broker, ['rabbitmq', 'both'], true)) {
            $success = $this->rabbit->publish($event) && $success;
        }

        if (in_array($outbox->broker, ['mqtt', 'both'], true)) {
            $success = $this->mqtt->publish($event) && $success;
        }

        if ($success) {
            $outbox->markPublished();
        } else {
            $outbox->markFailed('Publish failed to one or more brokers');
        }

        return $success;
    }
}
