<?php

namespace App\Services\EventBus;

use App\Events\BaseEvent;
use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Publishes domain events to RabbitMQ (internal message broker).
 * Uses a topic exchange so subscribers can bind with wildcards
 * e.g. `user.*`, `payment.*`, `#` (all events).
 */
class RabbitMqPublisher implements EventPublisher
{
    protected ?AMQPStreamConnection $connection = null;
    protected ?AMQPChannel $channel = null;

    public function __construct(protected array $config)
    {
    }

    public function publish(BaseEvent $event): bool
    {
        try {
            $this->connect();

            $message = new AMQPMessage(
                json_encode($event->envelope(), JSON_UNESCAPED_UNICODE),
                [
                    'content_type' => 'application/json',
                    'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                    'message_id' => $event->eventId,
                    'correlation_id' => $event->correlationId,
                    'timestamp' => now()->timestamp,
                    'app_id' => config('app.name'),
                ]
            );

            $this->channel->basic_publish(
                $message,
                $this->config['exchange'],
                $event->eventName
            );

            Log::channel('events')->info('Event published to RabbitMQ', [
                'event' => $event->eventName,
                'event_id' => $event->eventId,
                'exchange' => $this->config['exchange'],
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error('RabbitMQ publish failed', [
                'event' => $event->eventName,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    protected function connect(): void
    {
        if ($this->connection && $this->connection->isConnected()) {
            return;
        }

        $this->connection = new AMQPStreamConnection(
            $this->config['host'],
            $this->config['port'],
            $this->config['user'],
            $this->config['password'],
            $this->config['vhost'] ?? '/'
        );

        $this->channel = $this->connection->channel();

        $this->channel->exchange_declare(
            $this->config['exchange'],
            $this->config['exchange_type'] ?? 'topic',
            false,
            true,
            false
        );
    }

    public function __destruct()
    {
        try {
            $this->channel?->close();
            $this->connection?->close();
        } catch (\Throwable) {
        }
    }
}
