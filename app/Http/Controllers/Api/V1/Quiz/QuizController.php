<?php

namespace App\Http\Controllers\Api\V1\Quiz;

use App\Http\Controllers\Controller;
use App\Http\Requests\Quiz\StoreQuizRequest;
use App\Http\Resources\QuizResource;
use App\Models\Question;
use App\Models\QuestionBank;
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

    /** POST /api/v1/quizzes/{uuid}/questions/sync — replaces the entire question list */
    public function syncQuestions(Quiz $quiz, Request $request): JsonResponse
    {
        $data = $request->validate([
            'questions' => ['required', 'array', 'min:1'],
            'questions.*.question_id' => ['required'],
            'questions.*.position' => ['nullable', 'integer'],
            'questions.*.override_time_seconds' => ['nullable', 'integer'],
            'questions.*.override_points' => ['nullable', 'integer'],
        ]);

        foreach ($data['questions'] as $i => $q) {
            $id = $this->resolveQuestionId($q['question_id']);
            if (!$id) return $this->error("Question {$q['question_id']} not found", 422);
            $data['questions'][$i]['question_id'] = $id;
        }

        $updated = $this->quizService->syncQuestions($quiz, $data['questions']);
        return $this->success(new QuizResource($updated->load('questions')), 'Questions synced');
    }

    /** POST /api/v1/quizzes/{uuid}/attach-questions — append selected questions from bank(s) */
    public function attachQuestions(Quiz $quiz, Request $request): JsonResponse
    {
        $data = $request->validate([
            'question_uuids' => ['required', 'array', 'min:1'],
            'question_uuids.*' => ['string'],
        ]);

        $ids = Question::whereIn('uuid', $data['question_uuids'])->pluck('id')->all();
        if (empty($ids)) return $this->error('No matching questions found.', 422);

        $result = $this->quizService->attachQuestions($quiz, $ids);
        return $this->success([
            'attached' => $result['attached'],
            'total_questions' => $result['total'],
            'quiz' => new QuizResource($quiz->fresh(['questions'])),
        ], "Attached {$result['attached']} question(s).");
    }

    /** POST /api/v1/quizzes/{uuid}/attach-random — pick N random questions from a bank */
    public function attachRandom(Quiz $quiz, Request $request): JsonResponse
    {
        $data = $request->validate([
            'bank_uuid' => ['required', 'string', 'exists:question_banks,uuid'],
            'count' => ['required', 'integer', 'min:1', 'max:200'],
            'type' => ['nullable', 'string'],
            'difficulty' => ['nullable', 'in:easy,medium,hard'],
        ]);

        $bank = QuestionBank::where('uuid', $data['bank_uuid'])->firstOrFail();
        $result = $this->quizService->attachRandomFromBank(
            $quiz, $bank, (int) $data['count'],
            array_filter([
                'type' => $data['type'] ?? null,
                'difficulty' => $data['difficulty'] ?? null,
            ])
        );

        return $this->success([
            'requested' => $result['requested'],
            'attached' => $result['attached'],
            'total_questions' => $result['total'],
            'quiz' => new QuizResource($quiz->fresh(['questions'])),
        ], "Attached {$result['attached']} of {$result['requested']} random question(s).");
    }

    /** POST /api/v1/quizzes/{uuid}/detach-questions — remove selected questions */
    public function detachQuestions(Quiz $quiz, Request $request): JsonResponse
    {
        $data = $request->validate([
            'question_uuids' => ['required', 'array', 'min:1'],
            'question_uuids.*' => ['string'],
        ]);

        $ids = Question::whereIn('uuid', $data['question_uuids'])->pluck('id')->all();
        if (empty($ids)) return $this->error('No matching questions found.', 422);

        $result = $this->quizService->detachQuestions($quiz, $ids);
        return $this->success([
            'detached' => $result['detached'],
            'total_questions' => $result['total'],
            'quiz' => new QuizResource($quiz->fresh(['questions'])),
        ], "Removed {$result['detached']} question(s).");
    }

    /** POST /api/v1/quizzes/{uuid}/reorder-questions — accepts ordered UUIDs */
    public function reorderQuestions(Quiz $quiz, Request $request): JsonResponse
    {
        $data = $request->validate([
            'order' => ['required', 'array', 'min:1'],
            'order.*' => ['string'],
        ]);

        $ids = Question::whereIn('uuid', $data['order'])
            ->pluck('id', 'uuid')
            ->all();
        $ordered = [];
        foreach ($data['order'] as $uuid) {
            if (isset($ids[$uuid])) $ordered[] = $ids[$uuid];
        }
        if (empty($ordered)) return $this->error('No matching questions to reorder.', 422);

        $updated = $this->quizService->reorderQuestions($quiz, $ordered);
        return $this->success(new QuizResource($updated->load('questions')), 'Order updated');
    }

    /** POST /api/v1/quizzes/{uuid}/duplicate — clone a quiz + its questions as a draft */
    public function duplicate(Quiz $quiz, Request $request): JsonResponse
    {
        $copy = $this->quizService->duplicateQuiz($quiz, $request->user());
        return $this->success(new QuizResource($copy->load('questions')), 'Quiz duplicated', 201);
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

    /** Accept either a numeric ID or a UUID string for question references. */
    private function resolveQuestionId(mixed $identifier): ?int
    {
        if (is_int($identifier) || (is_string($identifier) && ctype_digit($identifier))) {
            return (int) $identifier;
        }
        if (is_string($identifier)) {
            return Question::where('uuid', $identifier)->value('id');
        }
        return null;
    }
}
