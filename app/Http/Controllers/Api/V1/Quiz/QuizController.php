<?php

namespace App\Http\Controllers\Api\V1\Quiz;

use App\Http\Controllers\Controller;
use App\Http\Requests\Quiz\StoreQuizRequest;
use App\Http\Resources\QuizResource;
use App\Models\Quiz;
use App\Services\Quiz\QuizService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QuizController extends Controller
{
    public function __construct(protected QuizService $quizService) {}

    /** GET /api/v1/quizzes */
    public function index(Request $request): JsonResponse
    {
        $q = Quiz::query()->with(['creator.profile']);

        if ($cat = $request->query('category')) $q->where('category', $cat);
        if ($mode = $request->query('mode')) $q->where('mode', $mode);
        if ($status = $request->query('status')) $q->where('status', $status);
        if ($search = $request->query('search')) $q->where('name', 'like', "%{$search}%");

        // Students only see published quizzes
        if (! $request->user()?->hasAnyRole(['system_admin', 'trainer'])) {
            $q->where('status', 'published');
        }

        return $this->success(
            QuizResource::collection($q->latest()->paginate($request->query('per_page', 20)))
        );
    }

    /** POST /api/v1/quizzes */
    public function store(StoreQuizRequest $request): JsonResponse
    {
        $quiz = $this->quizService->createQuiz($request->user(), $request->validated());
        return $this->success(new QuizResource($quiz), 'Quiz created', 201);
    }

    /** GET /api/v1/quizzes/{uuid} */
    public function show(Quiz $quiz): JsonResponse
    {
        return $this->success(new QuizResource($quiz->load(['questions', 'creator.profile'])));
    }

    /** PATCH /api/v1/quizzes/{uuid} */
    public function update(Quiz $quiz, StoreQuizRequest $request): JsonResponse
    {
        $updated = $this->quizService->updateQuiz($quiz, $request->validated());
        return $this->success(new QuizResource($updated), 'Quiz updated');
    }

    /** DELETE /api/v1/quizzes/{uuid} */
    public function destroy(Quiz $quiz): JsonResponse
    {
        $quiz->delete();
        return $this->success(null, 'Quiz archived');
    }

    /** POST /api/v1/quizzes/{uuid}/questions/sync */
    public function syncQuestions(Quiz $quiz, Request $request): JsonResponse
    {
        $data = $request->validate([
            'questions' => ['required', 'array', 'min:1'],
            'questions.*.question_id' => ['required', 'exists:questions,id'],
            'questions.*.position' => ['nullable', 'integer'],
            'questions.*.override_time_seconds' => ['nullable', 'integer'],
            'questions.*.override_points' => ['nullable', 'integer'],
        ]);

        $updated = $this->quizService->syncQuestions($quiz, $data['questions']);
        return $this->success(new QuizResource($updated->load('questions')), 'Questions synced');
    }

    /** POST /api/v1/quizzes/{uuid}/publish */
    public function publish(Quiz $quiz): JsonResponse
    {
        try {
            $published = $this->quizService->publish($quiz);
            return $this->success(new QuizResource($published), 'Quiz published');
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }
}
