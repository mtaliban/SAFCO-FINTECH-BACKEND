<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Base class for all domain events.
 * Provides a common structure so events can be published
 * consistently to RabbitMQ, MQTT (HiveMQ), and Laravel listeners.
 */
abstract class BaseEvent
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $eventId;
    public string $eventName;
    public string $occurredAt;
    public string $correlationId;

    public function __construct()
    {
        $this->eventId = (string) Str::uuid();
        $this->eventName = static::eventName();
        $this->occurredAt = now()->toIso8601String();
        $this->correlationId = request()?->header('X-Correlation-ID')
            ?? (string) Str::uuid();
    }

    /**
     * Unique dot-separated event name used as MQTT topic + AMQP routing key.
     * Example: user.registered, quiz.started, payment.completed
     */
    abstract public static function eventName(): string;

    /**
     * The payload published to message brokers.
     */
    abstract public function toPayload(): array;

    /**
     * Which brokers should receive this event.
     * Options: 'rabbitmq', 'mqtt', 'both'
     */
    public function broker(): string
    {
        return 'both';
    }

    /**
     * MQTT topic / RabbitMQ routing key.
     */
    public function routingKey(): string
    {
        return str_replace('.', '/', $this->eventName);
    }

    public function envelope(): array
    {
        return [
            'event_id' => $this->eventId,
            'event_name' => $this->eventName,
            'occurred_at' => $this->occurredAt,
            'correlation_id' => $this->correlationId,
            'payload' => $this->toPayload(),
        ];
    }
}
