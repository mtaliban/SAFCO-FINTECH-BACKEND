<?php

namespace App\Services\TrainerPortal;

use App\Models\Enrollment;
use App\Models\TrainerProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * SRS Module 13 — Trainer profile lifecycle.
 *
 * Responsibilities:
 *   - Get or create the profile for a User (idempotent, race-safe)
 *   - Generate a unique URL slug from the trainer's name
 *   - Recompute cached aggregates (students_taught_count)
 */
class TrainerProfileService
{
    public function getOrCreateFor(User $user): TrainerProfile
    {
        return DB::transaction(function () use ($user) {
            $existing = TrainerProfile::where('user_id', $user->id)
                ->lockForUpdate()->first();
            if ($existing) return $existing;

            return TrainerProfile::create([
                'user_id' => $user->id,
                'public_slug' => $this->generateSlug($user),
                'headline' => null,
                'is_public' => false,   // trainer must opt-in
                'is_verified' => false,
                'availability_status' => TrainerProfile::AVAILABILITY_AVAILABLE,
            ]);
        });
    }

    /**
     * Build a URL-safe slug from the user's display name, ensuring uniqueness
     * by appending a short random suffix on collision.
     */
    public function generateSlug(User $user): string
    {
        $base = Str::slug($user->profile?->full_name ?? explode('@', $user->email)[0]);
        if (empty($base)) $base = 'trainer';
        $base = Str::limit($base, 60, '');

        $candidate = $base;
        $attempts = 0;
        while (TrainerProfile::where('public_slug', $candidate)->exists()) {
            $attempts++;
            if ($attempts > 10) {
                // fallback to UUID fragment
                $candidate = $base . '-' . Str::lower(Str::random(8));
                break;
            }
            $candidate = $base . '-' . Str::lower(Str::random(6));
        }
        return $candidate;
    }

    /** Update the cached student count based on distinct enrollments in trainer's courses. */
    public function recacheStudentCount(TrainerProfile $profile): void
    {
        $count = (int) Enrollment::join('courses', 'courses.id', '=', 'enrollments.course_id')
            ->where('courses.instructor_id', $profile->user_id)
            ->whereNull('courses.deleted_at')
            ->distinct('enrollments.user_id')
            ->count('enrollments.user_id');

        $profile->update(['students_taught_count' => $count]);
    }
}
