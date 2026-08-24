<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * SRS 3.3 Student · SRS 3.4 Corporate Client — course enrollment.
 * Tracks progress_percentage (0-100) computed from lesson_completions.
 */
class Enrollment extends Model
{
    use HasFactory;

    protected $fillable = ['uuid', 'user_id', 'course_id', 'enrolled_at', 'progress_percentage', 'completed_at'];

    protected function casts(): array
    {
        return [
            'enrolled_at' => 'datetime',
            'completed_at' => 'datetime',
            'progress_percentage' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $e) {
            if (empty($e->uuid)) $e->uuid = (string) Str::uuid();
            if (empty($e->enrolled_at)) $e->enrolled_at = now();
        });

        // SRS Module 13 — keep trainer_profile.students_taught_count in sync.
        static::created(fn (self $e) => self::recacheTrainerStudentCount($e));
        static::deleted(fn (self $e) => self::recacheTrainerStudentCount($e));
    }

    private static function recacheTrainerStudentCount(self $e): void
    {
        $instructorId = optional($e->course()->first())->instructor_id;
        if (!$instructorId) return;
        $profile = \App\Models\TrainerProfile::where('user_id', $instructorId)->first();
        if ($profile) {
            app(\App\Services\TrainerPortal\TrainerProfileService::class)
                ->recacheStudentCount($profile);
        }
    }

    public function getRouteKeyName(): string { return 'uuid'; }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function course(): BelongsTo { return $this->belongsTo(Course::class); }
}
