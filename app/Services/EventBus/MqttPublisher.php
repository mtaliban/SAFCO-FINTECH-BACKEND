<?php

namespace App\Services\EventBus;

use App\Events\BaseEvent;
use Illuminate\Support\Facades\Log;
use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\Exceptions\MqttClientException;
use PhpMqtt\Client\MqttClient;

/**
 * Publishes domain events to HiveMQ (MQTT broker).
 * MQTT is ideal for mobile/IoT subscribers because it is lightweight,
 * uses persistent connections, and supports QoS levels.
 *
 * Topic layout: safco/lms/user/registered
 *               safco/lms/quiz/started
 *               safco/lms/leaderboard/{sessionId}
 */
class MqttPublisher implements EventPublisher
{
    protected ?MqttClient $client = null;

    public function __construct(protected array $config)
    {
    }

    public function publish(BaseEvent $event): bool
    {
        try {
            $client = $this->getClient();

            $topic = sprintf(
                '%s/%s',
                trim($this->config['topic_prefix'], '/'),
                $event->routingKey()
            );

            $payload = json_encode($event->envelope(), JSON_UNESCAPED_UNICODE);

            $client->publish(
                topic: $topic,
                message: $payload,
                qualityOfService: MqttClient::QOS_AT_LEAST_ONCE,
                retain: false
            );

            Log::channel('events')->info('Event published to MQTT (HiveMQ)', [
                'event' => $event->eventName,
                'topic' => $topic,
                'event_id' => $event->eventId,
            ]);

            return true;
        } catch (MqttClientException|\Throwable $e) {
            Log::error('MQTT publish failed', [
                'event' => $event->eventName,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Broadcast to a dynamic MQTT topic (used by real-time features
     * like Kahoot live leaderboard: safco/lms/leaderboard/{sessionPin})
     */
    public function publishRaw(string $topic, array $payload, int $qos = MqttClient::QOS_AT_MOST_ONCE): bool
    {
        try {
            $client = $this->getClient();
            $client->publish(
                topic: $topic,
                message: json_encode($payload, JSON_UNESCAPED_UNICODE),
                qualityOfService: $qos
            );
            return true;
        } catch (\Throwable $e) {
            Log::error('MQTT raw publish failed', ['topic' => $topic, 'error' => $e->getMessage()]);
            return false;
        }
    }

    protected function getClient(): MqttClient
    {
        if ($this->client && $this->client->isConnected()) {
            return $this->client;
        }

        $this->client = new MqttClient(
            $this->config['host'],
            (int) $this->config['port'],
            $this->config['client_id'] . '_' . uniqid()
        );

        $settings = (new ConnectionSettings())
            ->setUsername($this->config['username'] ?? null)
            ->setPassword($this->config['password'] ?? null)
            ->setKeepAliveInterval((int) ($this->config['keep_alive'] ?? 60))
            ->setUseTls((bool) ($this->config['tls_enabled'] ?? false));

        $this->client->connect($settings, (bool) ($this->config['clean_session'] ?? true));

        return $this->client;
    }

    public function __destruct()
    {
        try {
            $this->client?->disconnect();
        } catch (\Throwable) {
        }
    }
}
