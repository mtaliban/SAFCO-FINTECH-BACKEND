<?php

namespace App\Events\User;

use App\Events\BaseEvent;
use App\Models\User;

class UserLoggedOut extends BaseEvent
{
    public function __construct(
        public readonly User $user,
        public readonly ?string $ipAddress = null,
    ) {
        parent::__construct();
    }

    public static function eventName(): string
    {
        return 'user.logged_out';
    }

    public function toPayload(): array
    {
        return [
            'user_id' => $this->user->id,
            'user_uuid' => $this->user->uuid,
            'ip_address' => $this->ipAddress,
            'logged_out_at' => now()->toIso8601String(),
        ];
    }
}
