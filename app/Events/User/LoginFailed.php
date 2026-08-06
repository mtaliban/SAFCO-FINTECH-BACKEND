<?php

namespace App\Events\User;

use App\Events\BaseEvent;

class LoginFailed extends BaseEvent
{
    public function __construct(
        public readonly string $identifier,
        public readonly string $reason,
        public readonly ?string $ipAddress = null,
        public readonly ?string $userAgent = null,
    ) {
        parent::__construct();
    }

    public static function eventName(): string
    {
        return 'user.login_failed';
    }

    public function toPayload(): array
    {
        return [
            'identifier' => $this->identifier,
            'reason' => $this->reason,
            'ip_address' => $this->ipAddress,
            'user_agent' => $this->userAgent,
            'attempted_at' => now()->toIso8601String(),
        ];
    }

    public function broker(): string
    {
        // Only publish failed logins to internal broker (security team)
        return 'rabbitmq';
    }
}
