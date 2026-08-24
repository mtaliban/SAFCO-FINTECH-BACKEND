<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * SRS Module 13 — Student → Trainer review.
 * A student may leave AT MOST ONE review per (trainer, course) combination.
 * Trainer.rating_avg + rating_count are recomputed by ReviewService on every write.
 */
class TrainerReview extends Model
{
    use SoftDeletes;

    const STATUS_PENDING   = 'pending';
    const STATUS_PUBLISHED = 'published';
    const STATUS_HIDDEN    = 'hidden';

    protected $fillable = [
        'uuid', 'trainer_profile_id', 'student_id', 'course_id',
        'rating', 'review_text',
        'status', 'moderation_note', 'moderated_by', 'moderated_at',
    ];

    protected $casts = [
        'rating' => 'integer',
        'moderated_at' => 'datetime',
    ];

    public function getRouteKeyName(): string { return 'uuid'; }

    protected static function booted(): void
    {
        static::creating(function (self $r) {
            if (empty($r->uuid)) $r->uuid = (string) Str::uuid();
        });
    }

    public function trainerProfile(): BelongsTo
    {
        return $this->belongsTo(TrainerProfile::class, 'trainer_profile_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function moderator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderated_by');
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }
}
