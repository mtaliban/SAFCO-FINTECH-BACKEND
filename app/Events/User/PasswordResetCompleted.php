<?php

namespace App\Events\User;

use App\Events\BaseEvent;
use App\Models\User;

class PasswordResetCompleted extends BaseEvent
{
    public function __construct(public readonly User $user)
    {
        parent::__construct();
    }

    public static function eventName(): string
    {
        return 'user.password_reset_completed';
    }

    public function toPayload(): array
    {
        return [
            'user_id' => $this->user->id,
            'user_uuid' => $this->user->uuid,
            'email' => $this->user->email,
            'reset_at' => now()->toIso8601String(),
        ];
    }
}
