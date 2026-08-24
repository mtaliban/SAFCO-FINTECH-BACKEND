<?php

namespace App\Services\TrainerPortal;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\TrainerProfile;
use App\Models\TrainerReview;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * SRS Module 13 — Review submission and rating aggregation.
 *
 * Business rules:
 *   - Student MUST have a completed_at enrollment in a course by this trainer.
 *   - A student can leave at most ONE review per (trainer, course).
 *   - rating_avg + rating_count on TrainerProfile are recomputed on every write.
 *   - Only 'published' reviews count toward the aggregate.
 */
class ReviewService
{
    /**
     * @throws \DomainException if the student is not eligible to review this trainer.
     */
    public function submit(
        TrainerProfile $trainer,
        User $student,
        Course $course,
        int $rating,
        ?string $reviewText,
    ): TrainerReview {
        if ($rating < 1 || $rating > 5) {
            throw new \DomainException('Rating must be between 1 and 5.');
        }
        if ($course->instructor_id !== $trainer->user_id) {
            throw new \DomainException('This course was not delivered by this trainer.');
        }

        $enrollment = Enrollment::where('user_id', $student->id)
            ->where('course_id', $course->id)
            ->whereNotNull('completed_at')
            ->first();
        if (!$enrollment) {
            throw new \DomainException('Only students who completed the course can review the trainer.');
        }

        return DB::transaction(function () use ($trainer, $student, $course, $rating, $reviewText) {
            $review = TrainerReview::updateOrCreate(
                [
                    'trainer_profile_id' => $trainer->id,
                    'student_id' => $student->id,
                    'course_id' => $course->id,
                ],
                [
                    'rating' => $rating,
                    'review_text' => $reviewText,
                    'status' => TrainerReview::STATUS_PUBLISHED,
                ]
            );

            $this->recomputeAggregate($trainer);
            return $review->fresh();
        });
    }

    /**
     * Recompute rating_avg + rating_count from published reviews.
     * Call this after any review write/hide operation.
     */
    public function recomputeAggregate(TrainerProfile $trainer): void
    {
        $stats = TrainerReview::where('trainer_profile_id', $trainer->id)
            ->where('status', TrainerReview::STATUS_PUBLISHED)
            ->selectRaw('COUNT(*) as c, AVG(rating) as avg_rating')
            ->first();

        $trainer->update([
            'rating_count' => (int) ($stats?->c ?? 0),
            'rating_avg' => $stats && $stats->c > 0
                ? round((float) $stats->avg_rating, 2)
                : null,
        ]);
    }

    public function hide(TrainerReview $review, User $moderator, ?string $note = null): void
    {
        $review->update([
            'status' => TrainerReview::STATUS_HIDDEN,
            'moderation_note' => $note,
            'moderated_by' => $moderator->id,
            'moderated_at' => now(),
        ]);
        $this->recomputeAggregate($review->trainerProfile);
    }
}
