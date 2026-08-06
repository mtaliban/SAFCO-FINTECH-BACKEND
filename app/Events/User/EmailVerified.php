<?php

namespace App\Events\User;

use App\Events\BaseEvent;
use App\Models\User;

class EmailVerified extends BaseEvent
{
    public function __construct(public readonly User $user)
    {
        parent::__construct();
    }

    public static function eventName(): string
    {
        return 'user.email_verified';
    }

    public function toPayload(): array
    {
        return [
            'user_id' => $this->user->id,
            'user_uuid' => $this->user->uuid,
            'email' => $this->user->email,
            'verified_at' => $this->user->email_verified_at?->toIso8601String(),
        ];
    }
}
