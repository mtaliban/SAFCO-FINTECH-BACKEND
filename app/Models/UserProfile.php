<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class UserProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'full_name',
        'first_name',
        'last_name',
        'middle_name',
        'gender',
        'date_of_birth',
        'nationality',
        'national_id',
        'position',
        'department',
        'bio',
        'profile_picture',
        'profile_picture_thumbnail',
        'cover_photo',
        'address_line1',
        'address_line2',
        'city',
        'region',
        'postal_code',
        'country',
        'social_links',
        'preferences',
        'metadata',
        'profile_completed_at',
        'profile_completion_percentage',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'social_links' => 'array',
            'preferences' => 'array',
            'metadata' => 'array',
            'profile_completed_at' => 'datetime',
            'profile_completion_percentage' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $profile) {
            $profile->profile_completion_percentage = $profile->calculateCompletionPercentage();

            if ($profile->profile_completion_percentage === 100 && ! $profile->profile_completed_at) {
                $profile->profile_completed_at = now();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getProfilePictureUrlAttribute(): ?string
    {
        return $this->profile_picture
            ? Storage::disk(config('filesystems.default'))->url($this->profile_picture)
            : null;
    }

    public function getProfilePictureThumbnailUrlAttribute(): ?string
    {
        return $this->profile_picture_thumbnail
            ? Storage::disk(config('filesystems.default'))->url($this->profile_picture_thumbnail)
            : null;
    }

    public function calculateCompletionPercentage(): int
    {
        $fields = [
            'full_name', 'gender', 'date_of_birth', 'position',
            'profile_picture', 'address_line1', 'city', 'country',
        ];

        $filled = collect($fields)->filter(fn ($f) => ! empty($this->{$f}))->count();

        return (int) round(($filled / count($fields)) * 100);
    }
}
