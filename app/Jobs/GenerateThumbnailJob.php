<?php

namespace App\Jobs;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Laravel\Facades\Image;

class GenerateThumbnailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public string $queue = 'media';

    public function __construct(
        public int $userId,
        public string $originalPath,
    ) {
    }

    public function handle(): void
    {
        $user = User::find($this->userId);
        if (! $user || ! $user->profile) {
            return;
        }

        $disk = Storage::disk(config('filesystems.default'));
        if (! $disk->exists($this->originalPath)) {
            return;
        }

        $binary = $disk->get($this->originalPath);
        $image = Image::read($binary)->cover(200, 200);

        $thumbPath = Str::replaceLast(
            '.',
            '_thumb.',
            $this->originalPath
        );

        $disk->put($thumbPath, (string) $image->encode(), 'public');

        $user->profile->update([
            'profile_picture_thumbnail' => $thumbPath,
        ]);
    }
}
