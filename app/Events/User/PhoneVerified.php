<?php

namespace App\Events\User;

use App\Events\BaseEvent;
use App\Models\User;

class PhoneVerified extends BaseEvent
{
    public function __construct(public readonly User $user)
    {
        parent::__construct();
    }

    public static function eventName(): string
    {
        return 'user.phone_verified';
    }

    public function toPayload(): array
    {
        return [
            'user_id' => $this->user->id,
            'user_uuid' => $this->user->uuid,
            'phone' => $this->user->phone,
            'verified_at' => $this->user->phone_verified_at?->toIso8601String(),
        ];
    }
}
