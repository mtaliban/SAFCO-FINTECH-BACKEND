<?php

namespace App\Events\User;

use App\Events\BaseEvent;

class OtpRequested extends BaseEvent
{
    public function __construct(
        public readonly string $identifier,
        public readonly string $code,
        public readonly string $type,
        public readonly string $channel,
        public readonly ?int $userId = null,
    ) {
        parent::__construct();
    }

    public static function eventName(): string
    {
        return 'user.otp_requested';
    }

    public function toPayload(): array
    {
        return [
            'user_id' => $this->userId,
            'identifier' => $this->identifier,
            'type' => $this->type,
            'channel' => $this->channel,
            'requested_at' => now()->toIso8601String(),
            // OTP code intentionally NOT sent to broker
        ];
    }

    public function broker(): string
    {
        // Internal only - contains sensitive info
        return 'rabbitmq';
    }
}
