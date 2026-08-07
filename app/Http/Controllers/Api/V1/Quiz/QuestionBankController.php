<?php

namespace App\Http\Controllers\Api\V1\Quiz;

use App\Http\Controllers\Controller;
use App\Http\Requests\Quiz\StoreQuestionRequest;
use App\Http\Resources\QuestionResource;
use App\Models\Question;
use App\Models\QuestionBank;
use App\Services\Quiz\QuestionBankService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QuestionBankController extends Controller
{
    public function __construct(protected QuestionBankService $bankService) {}

    /** GET /api/v1/question-banks */
    public function index(Request $request): JsonResponse
    {
        $q = QuestionBank::query()->withCount('questions');

        if ($cat = $request->query('category')) $q->where('category', $cat);
        if ($search = $request->query('search')) $q->where('name', 'like', "%{$search}%");

        return $this->success($q->latest()->paginate($request->query('per_page', 20)));
    }

    /** POST /api/v1/question-banks */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'category' => ['nullable', 'string'],
            'difficulty' => ['nullable', 'in:beginner,intermediate,advanced,expert'],
            'is_public' => ['nullable', 'boolean'],
        ]);

        $bank = $this->bankService->createBank($request->user(), $data);
        return $this->success($bank, 'Question bank created', 201);
    }

    /** GET /api/v1/question-banks/{uuid} */
    public function show(QuestionBank $questionBank): JsonResponse
    {
        return $this->success($questionBank->load('questions'));
    }

    /** POST /api/v1/question-banks/{uuid}/questions */
    public function addQuestion(QuestionBank $questionBank, StoreQuestionRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Auto-style multiple choice options with Kahoot colors + shapes
        if ($data['type'] === 'multiple_choice' && ! empty($data['options'])) {
            $data['options'] = $this->bankService->styleOptions($data['options']);
        }

        $question = $this->bankService->addQuestion($questionBank, $request->user(), $data);
        return $this->success(new QuestionResource($question), 'Question added', 201);
    }

    /** PATCH /api/v1/questions/{uuid} */
    public function updateQuestion(Question $question, StoreQuestionRequest $request): JsonResponse
    {
        $updated = $this->bankService->updateQuestion($question, $request->validated());
        return $this->success(new QuestionResource($updated), 'Question updated');
    }

    /** DELETE /api/v1/questions/{uuid} */
    public function deleteQuestion(Question $question): JsonResponse
    {
        $this->bankService->deleteQuestion($question);
        return $this->success(null, 'Question deleted');
    }
}
