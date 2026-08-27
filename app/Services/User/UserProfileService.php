<?php

namespace App\Services\User;

use App\Events\User\ProfilePictureUploaded;
use App\Events\User\ProfileUpdated;
use App\Models\User;
use App\Services\EventBus\EventDispatcher;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class UserProfileService
{
    public function __construct(protected EventDispatcher $events)
    {
    }

    public function update(User $user, array $data): User
    {
        return DB::transaction(function () use ($user, $data) {
            // Phone lives on users table, not user_profiles
            if (array_key_exists('phone', $data)) {
                $user->update(['phone' => $data['phone']]);
                unset($data['phone']);
            }

            $profile = $user->profile ?? $user->profile()->create(['full_name' => $data['full_name'] ?? $user->email]);

            $changes = array_diff_assoc($data, $profile->only(array_keys($data)));
            $profile->update($data);

            if (! empty($changes)) {
                $this->events->dispatch(
                    new ProfileUpdated($user->fresh('profile'), $changes),
                    aggregateType: User::class,
                    aggregateId: $user->id
                );
            }

            return $user->fresh('profile');
        });
    }

    /**
     * Store the uploaded profile picture on the configured disk (S3/Spaces)
     * and emit an event so a background job can generate thumbnails.
     */
    public function uploadProfilePicture(User $user, UploadedFile $file): User
    {
        $filename = sprintf(
            'profile-pictures/%s/%s.%s',
            $user->uuid,
            Str::random(20),
            $file->getClientOriginalExtension()
        );

        $path = Storage::disk(config('filesystems.default'))
            ->putFileAs(dirname($filename), $file, basename($filename), 'public');

        $profile = $user->profile;
        $oldPath = $profile->profile_picture;
        $profile->update(['profile_picture' => $path]);

        if ($oldPath) {
            Storage::disk(config('filesystems.default'))->delete($oldPath);
        }

        $this->events->dispatch(
            new ProfilePictureUploaded($user, $path),
            aggregateType: User::class,
            aggregateId: $user->id
        );

        return $user->fresh('profile');
    }
}
