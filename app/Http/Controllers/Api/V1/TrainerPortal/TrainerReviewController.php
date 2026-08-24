<?php

namespace App\Http\Controllers\Api\V1\TrainerPortal;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\TrainerProfile;
use App\Services\TrainerPortal\ReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SRS Module 13 — Student review submission.
 * Post-completion only, one per (trainer, course) — enforced in ReviewService.
 */
class TrainerReviewController extends Controller
{
    public function __construct(private readonly ReviewService $reviews) {}

    /** POST /trainers/{slug}/reviews */
    public function store(TrainerProfile $trainer, Request $request): JsonResponse
    {
        $data = $request->validate([
            'course_uuid' => ['required', 'exists:courses,uuid'],
            'rating' => ['required', 'integer', 'between:1,5'],
            'review_text' => ['nullable', 'string', 'max:2000'],
        ]);

        $course = Course::where('uuid', $data['course_uuid'])->firstOrFail();

        try {
            $review = $this->reviews->submit(
                trainer: $trainer,
                student: $request->user(),
                course: $course,
                rating: (int) $data['rating'],
                reviewText: $data['review_text'] ?? null,
            );
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success([
            'review' => [
                'id' => $review->uuid,
                'rating' => $review->rating,
                'text' => $review->review_text,
                'created_at' => $review->created_at->toIso8601String(),
            ],
            'trainer_rating_avg' => (float) $trainer->fresh()->rating_avg,
            'trainer_rating_count' => (int) $trainer->fresh()->rating_count,
        ], 'Review submitted', 201);
    }

    /** GET /trainer/portal/reviews — trainer sees own received reviews */
    public function myReviews(Request $request): JsonResponse
    {
        $tp = $request->user()->trainerProfile;
        if (!$tp) return $this->success(['reviews' => []]);

        $reviews = $tp->reviews()
            ->with(['student:id,uuid,email', 'student.profile:user_id,full_name', 'course:id,uuid,title'])
            ->latest()
            ->paginate(20);

        return $this->success([
            'data' => $reviews->getCollection()->map(fn ($r) => [
                'id' => $r->uuid,
                'rating' => $r->rating,
                'text' => $r->review_text,
                'student_name' => $r->student->profile?->full_name ?? 'Student',
                'course_title' => $r->course?->title,
                'status' => $r->status,
                'created_at' => $r->created_at?->toIso8601String(),
            ]),
            'meta' => [
                'current_page' => $reviews->currentPage(),
                'last_page' => $reviews->lastPage(),
                'total' => $reviews->total(),
                'avg' => (float) $tp->rating_avg,
                'count' => (int) $tp->rating_count,
            ],
        ]);
    }
}
