<?php

namespace App\Events\User;

use App\Events\BaseEvent;
use App\Models\User;

class PasswordResetRequested extends BaseEvent
{
    public function __construct(
        public readonly User $user,
        public readonly string $token,
        public readonly string $channel = 'email',
    ) {
        parent::__construct();
    }

    public static function eventName(): string
    {
        return 'user.password_reset_requested';
    }

    public function toPayload(): array
    {
        return [
            'user_id' => $this->user->id,
            'user_uuid' => $this->user->uuid,
            'email' => $this->user->email,
            'channel' => $this->channel,
            'requested_at' => now()->toIso8601String(),
            // Token intentionally NOT sent to broker (security)
        ];
    }
}
