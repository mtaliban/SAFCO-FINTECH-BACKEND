<?php

namespace App\Events\User;

use App\Events\BaseEvent;
use App\Models\User;

class ProfileUpdated extends BaseEvent
{
    public function __construct(
        public readonly User $user,
        public readonly array $changes = [],
    ) {
        parent::__construct();
    }

    public static function eventName(): string
    {
        return 'user.profile_updated';
    }

    public function toPayload(): array
    {
        return [
            'user_id' => $this->user->id,
            'user_uuid' => $this->user->uuid,
            'changes' => $this->changes,
            'completion_percentage' => $this->user->profile?->profile_completion_percentage ?? 0,
            'updated_at' => now()->toIso8601String(),
        ];
    }
}
