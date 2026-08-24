<?php

namespace App\Http\Controllers\Api\V1\Trainer;

use App\Http\Controllers\Controller;
use App\Http\Resources\QuizResource;
use App\Models\Quiz;
use App\Models\QuizSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Trainer's own quizzes + past sessions (SRS 3.2 "Create quizzes · Monitor progress").
 */
class MyQuizzesController extends Controller
{
    /** GET /api/v1/trainer/my-quizzes */
    public function index(Request $request): JsonResponse
    {
        $quizzes = Quiz::where('created_by', $request->user()->id)
            ->latest()
            ->paginate((int) $request->query('per_page', 20));

        return $this->success(QuizResource::collection($quizzes)->response()->getData(true));
    }

    /** GET /api/v1/trainer/my-sessions */
    public function sessions(Request $request): JsonResponse
    {
        $sessions = QuizSession::whereHas('quiz', fn ($q) => $q->where('created_by', $request->user()->id))
            ->with(['quiz:id,uuid,name'])
            ->latest()
            ->paginate((int) $request->query('per_page', 20));

        return $this->success([
            'data' => $sessions->getCollection()->map(fn ($s) => [
                'uuid' => $s->uuid,
                'pin' => $s->pin,
                'quiz' => ['uuid' => $s->quiz?->uuid, 'name' => $s->quiz?->name],
                'status' => $s->status,
                'mode' => $s->mode,
                'participant_count' => $s->participant_count,
                'total_questions' => $s->total_questions,
                'started_at' => $s->started_at?->toIso8601String(),
                'ended_at' => $s->ended_at?->toIso8601String(),
                'created_at' => $s->created_at?->toIso8601String(),
            ]),
            'meta' => [
                'current_page' => $sessions->currentPage(),
                'last_page' => $sessions->lastPage(),
                'per_page' => $sessions->perPage(),
                'total' => $sessions->total(),
            ],
        ]);
    }
}
