<?php

namespace App\Listeners\User;

use App\Events\User\ProfilePictureUploaded;
use App\Jobs\GenerateThumbnailJob;
use Illuminate\Contracts\Queue\ShouldQueue;

class GenerateProfileThumbnail implements ShouldQueue
{
    public string $queue = 'media';

    public function handle(ProfilePictureUploaded $event): void
    {
        GenerateThumbnailJob::dispatch(
            userId: $event->user->id,
            originalPath: $event->originalPath,
        );
    }
}
