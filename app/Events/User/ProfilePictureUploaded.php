<?php

namespace App\Events\User;

use App\Events\BaseEvent;
use App\Models\User;

class ProfilePictureUploaded extends BaseEvent
{
    public function __construct(
        public readonly User $user,
        public readonly string $originalPath,
    ) {
        parent::__construct();
    }

    public static function eventName(): string
    {
        return 'user.profile_picture_uploaded';
    }

    public function toPayload(): array
    {
        return [
            'user_id' => $this->user->id,
            'user_uuid' => $this->user->uuid,
            'original_path' => $this->originalPath,
            'uploaded_at' => now()->toIso8601String(),
        ];
    }
}
