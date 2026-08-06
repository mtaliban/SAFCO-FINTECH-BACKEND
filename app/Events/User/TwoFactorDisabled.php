<?php

namespace App\Events\User;

use App\Events\BaseEvent;
use App\Models\User;

class TwoFactorDisabled extends BaseEvent
{
    public function __construct(
        public readonly User $user,
        public readonly string $method,
    ) {
        parent::__construct();
    }

    public static function eventName(): string
    {
        return 'user.two_factor_disabled';
    }

    public function toPayload(): array
    {
        return [
            'user_id' => $this->user->id,
            'user_uuid' => $this->user->uuid,
            'method' => $this->method,
            'disabled_at' => now()->toIso8601String(),
        ];
    }
}
