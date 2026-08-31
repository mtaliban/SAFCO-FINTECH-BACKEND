<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast to the user's private Reverb channel immediately after
 * InAppChannel writes the notification to the database.
 * ShouldBroadcastNow bypasses the queue so the push is instant.
 */
class InAppNotificationSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        private readonly int $userId,
        public readonly string $id,
        public readonly string $eventKey,
        public readonly string $title,
        public readonly string $body,
        public readonly ?string $actionUrl,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("user.{$this->userId}");
    }

    /** Event name the frontend listens for: .notification.sent */
    public function broadcastAs(): string
    {
        return 'notification.sent';
    }

    public function broadcastWith(): array
    {
        return [
            'id'         => $this->id,
            'event_key'  => $this->eventKey,
            'title'      => $this->title,
            'body'       => $this->body,
            'action_url' => $this->actionUrl,
        ];
    }
}
