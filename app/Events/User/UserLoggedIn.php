<?php

namespace App\Events\User;

use App\Events\BaseEvent;
use App\Models\User;

class UserLoggedIn extends BaseEvent
{
    public function __construct(
        public readonly User $user,
        public readonly string $authMethod = 'email',
        public readonly ?string $ipAddress = null,
        public readonly ?string $userAgent = null,
        public readonly ?string $deviceType = null,
    ) {
        parent::__construct();
    }

    public static function eventName(): string
    {
        return 'user.logged_in';
    }

    public function toPayload(): array
    {
        return [
            'user_id' => $this->user->id,
            'user_uuid' => $this->user->uuid,
            'email' => $this->user->email,
            'auth_method' => $this->authMethod,
            'ip_address' => $this->ipAddress,
            'user_agent' => $this->userAgent,
            'device_type' => $this->deviceType,
            'logged_in_at' => now()->toIso8601String(),
        ];
    }
}
