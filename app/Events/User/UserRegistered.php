<?php

namespace App\Events\User;

use App\Events\BaseEvent;
use App\Models\User;

class UserRegistered extends BaseEvent
{
    public function __construct(
        public readonly User $user,
        public readonly string $registrationChannel = 'email',
    ) {
        parent::__construct();
    }

    public static function eventName(): string
    {
        return 'user.registered';
    }

    public function toPayload(): array
    {
        return [
            'user_id' => $this->user->id,
            'user_uuid' => $this->user->uuid,
            'email' => $this->user->email,
            'phone' => $this->user->phone,
            'auth_provider' => $this->user->auth_provider,
            'registration_channel' => $this->registrationChannel,
            'organization_id' => $this->user->organization_id,
            'registered_at' => $this->user->created_at->toIso8601String(),
        ];
    }
}
