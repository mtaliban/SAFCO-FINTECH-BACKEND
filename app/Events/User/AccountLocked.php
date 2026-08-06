<?php

namespace App\Events\User;

use App\Events\BaseEvent;
use App\Models\User;

class AccountLocked extends BaseEvent
{
    public function __construct(
        public readonly User $user,
        public readonly string $reason,
        public readonly int $lockDurationMinutes = 30,
    ) {
        parent::__construct();
    }

    public static function eventName(): string
    {
        return 'user.account_locked';
    }

    public function toPayload(): array
    {
        return [
            'user_id' => $this->user->id,
            'user_uuid' => $this->user->uuid,
            'email' => $this->user->email,
            'reason' => $this->reason,
            'lock_duration_minutes' => $this->lockDurationMinutes,
            'locked_until' => $this->user->locked_until?->toIso8601String(),
        ];
    }
}
