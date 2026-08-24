<?php

namespace App\Http\Controllers\Api\V1\Student;

use App\Http\Controllers\Controller;
use App\Models\Quiz;
use App\Models\QuizSessionParticipant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Student's own quiz attempts + available quizzes (SRS 3.3
 * "Attempt quizzes · Track progress").
 */
class MyLearningController extends Controller
{
    /** GET /api/v1/student/my-attempts */
    public function attempts(Request $request): JsonResponse
    {
        // Live-quiz participations tied to this user (joined while logged-in).
        // Anonymous joins by nickname only aren't linked to any account.
        $participations = QuizSessionParticipant::with(['session.quiz:id,uuid,name'])
            ->where('user_id', $request->user()->id)
            ->latest()
            ->paginate((int) $request->query('per_page', 20));

        return $this->success([
            'data' => $participations->getCollection()->map(fn ($p) => [
                'uuid' => $p->uuid,
                'nickname' => $p->nickname,
                'total_score' => (int) $p->total_score,
                'correct_answers' => (int) $p->correct_answers,
                'longest_streak' => (int) ($p->longest_streak ?? 0),
                'quiz' => ['uuid' => $p->session?->quiz?->uuid, 'name' => $p->session?->quiz?->name],
                'session' => ['pin' => $p->session?->pin, 'status' => $p->session?->status],
                'played_at' => $p->created_at?->toIso8601String(),
            ]),
            'meta' => [
                'current_page' => $participations->currentPage(),
                'last_page' => $participations->lastPage(),
                'per_page' => $participations->perPage(),
                'total' => $participations->total(),
            ],
        ]);
    }

    /** GET /api/v1/student/available-quizzes */
    public function available(Request $request): JsonResponse
    {
        $quizzes = Quiz::where('status', 'published')
            ->latest()
            ->paginate((int) $request->query('per_page', 20));

        return $this->success([
            'data' => $quizzes->getCollection()->map(fn ($q) => [
                'uuid' => $q->uuid,
                'name' => $q->name,
                'description' => $q->description,
                'category' => $q->category,
                'difficulty' => $q->difficulty,
                'number_of_questions' => (int) $q->number_of_questions,
                'duration_minutes' => (int) $q->duration_minutes,
                'mode' => $q->mode,
            ]),
            'meta' => [
                'current_page' => $quizzes->currentPage(),
                'last_page' => $quizzes->lastPage(),
                'per_page' => $quizzes->perPage(),
                'total' => $quizzes->total(),
            ],
        ]);
    }
}
