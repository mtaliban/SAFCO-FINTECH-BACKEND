<?php

namespace App\Http\Controllers\Api\V1\Quiz;

use App\Http\Controllers\Controller;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Services\Quiz\AttemptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SRS Module 8: Examination System — student endpoints.
 *
 *   POST   /api/v1/quizzes/{uuid}/attempts        → start (or resume) an attempt
 *   GET    /api/v1/attempts/{uuid}                → attempt state for the taking UI
 *   POST   /api/v1/attempts/{uuid}/answer         → submit / update an answer
 *   POST   /api/v1/attempts/{uuid}/complete       → student-triggered submission
 *   POST   /api/v1/attempts/{uuid}/violation      → anti-cheat violation reporting
 *   GET    /api/v1/student/my-attempts            → list of my attempts (filterable by quiz)
 *   GET    /api/v1/student/exams                  → available exams (published mode=exam quizzes)
 */
class AttemptController extends Controller
{
    public function __construct(protected AttemptService $attempts) {}

    /** POST /api/v1/quizzes/{uuid}/attempts */
    public function start(Quiz $quiz, Request $request): JsonResponse
    {
        try {
            $attempt = $this->attempts->startAttempt($request->user(), $quiz);
            return $this->success(
                $this->attempts->getAttemptForTaking($attempt),
                'Attempt started',
                201,
            );
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /** GET /api/v1/attempts/{uuid} */
    public function show(QuizAttempt $attempt, Request $request): JsonResponse
    {
        $this->authorizeOwnership($attempt, $request);
        $attempt = $this->attempts->expireAttemptIfDue($attempt);

        // For completed / expired attempts, return the summary result view.
        if ($attempt->status !== 'in_progress') {
            return $this->success($this->resultSummary($attempt));
        }
        return $this->success($this->attempts->getAttemptForTaking($attempt));
    }

    /** POST /api/v1/attempts/{uuid}/answer */
    public function answer(QuizAttempt $attempt, Request $request): JsonResponse
    {
        $this->authorizeOwnership($attempt, $request);
        $data = $request->validate([
            'question_id' => ['required', 'string'],
            'answer' => ['nullable'],
        ]);
        try {
            $result = $this->attempts->submitAnswer($attempt, $data['question_id'], $data['answer'] ?? null);
            return $this->success($result);
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /** POST /api/v1/attempts/{uuid}/complete */
    public function complete(QuizAttempt $attempt, Request $request): JsonResponse
    {
        $this->authorizeOwnership($attempt, $request);
        $updated = $this->attempts->completeAttempt($attempt);
        return $this->success($this->resultSummary($updated), 'Attempt submitted');
    }

    /** POST /api/v1/attempts/{uuid}/violation */
    public function violation(QuizAttempt $attempt, Request $request): JsonResponse
    {
        $this->authorizeOwnership($attempt, $request);
        $data = $request->validate([
            'type' => ['required', 'string', 'in:tab_switch,fullscreen_exit,copy_paste,right_click,visibility_hidden,dev_tools'],
            'meta' => ['nullable', 'array'],
        ]);
        $updated = $this->attempts->logViolation($attempt, $data['type'], $data['meta'] ?? []);
        return $this->success([
            'violations_count' => count($updated->violations ?? []),
            'status' => $updated->status,
            'auto_submit_reason' => $updated->auto_submit_reason,
        ]);
    }

    /** GET /api/v1/student/my-attempts */
    public function myAttempts(Request $request): JsonResponse
    {
        $q = QuizAttempt::query()
            ->with('quiz:id,uuid,name,mode,exam_type,category,passing_mark_percentage')
            ->where('user_id', $request->user()->id);
        if ($quizUuid = $request->query('quiz')) {
            $q->whereHas('quiz', fn ($qq) => $qq->where('uuid', $quizUuid));
        }
        $page = $q->latest()->paginate((int) $request->query('per_page', 20));

        $items = $page->getCollection()->map(fn (QuizAttempt $a) => [
            'id' => $a->uuid,
            'attempt_number' => $a->attempt_number,
            'status' => $a->status,
            'exam_type' => $a->exam_type,
            'total_questions' => $a->total_questions,
            'correct_answers' => $a->correct_answers,
            'percentage' => (float) $a->percentage,
            'passed' => (bool) $a->passed,
            'duration_seconds' => $a->duration_seconds,
            'started_at' => $a->started_at?->toIso8601String(),
            'completed_at' => $a->completed_at?->toIso8601String(),
            'auto_submit_reason' => $a->auto_submit_reason,
            'quiz' => $a->quiz ? [
                'id' => $a->quiz->uuid,
                'name' => $a->quiz->name,
                'mode' => $a->quiz->mode,
                'exam_type' => $a->quiz->exam_type,
                'category' => $a->quiz->category,
                'passing_mark_percentage' => $a->quiz->passing_mark_percentage,
            ] : null,
        ]);

        return $this->success([
            'data' => $items,
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /** GET /api/v1/student/exams — published exam-mode quizzes with attempts-remaining hint */
    public function availableExams(Request $request): JsonResponse
    {
        $user = $request->user();
        $quizzes = Quiz::query()
            ->where('status', 'published')
            ->where('mode', 'exam')
            ->latest()
            ->get();

        $data = $quizzes->map(function (Quiz $q) use ($user) {
            $remaining = $q->attemptsRemainingFor($user);
            $bestAttempt = $q->attempts()
                ->where('user_id', $user->id)
                ->orderByDesc('percentage')
                ->first();

            return [
                'id' => $q->uuid,
                'name' => $q->name,
                'description' => $q->description,
                'category' => $q->category,
                'difficulty' => $q->difficulty,
                'exam_type' => $q->exam_type ?? 'practice',
                'duration_minutes' => $q->duration_minutes,
                'number_of_questions' => $q->number_of_questions,
                'passing_mark_percentage' => $q->passing_mark_percentage,
                'max_attempts' => $q->max_attempts,
                'attempts_remaining' => $remaining, // null = unlimited
                'best_result' => $bestAttempt ? [
                    'percentage' => (float) $bestAttempt->percentage,
                    'passed' => (bool) $bestAttempt->passed,
                    'completed_at' => $bestAttempt->completed_at?->toIso8601String(),
                    'attempt_id' => $bestAttempt->uuid,
                ] : null,
                'anti_cheat_settings' => $q->anti_cheat_settings,
            ];
        });

        return $this->success($data);
    }

    /* ------------------------------------------------------------ */

    protected function authorizeOwnership(QuizAttempt $attempt, Request $request): void
    {
        $user = $request->user();
        if ($attempt->user_id !== $user->id && ! $user->hasAnyRole(['system_admin', 'trainer'])) {
            abort(403, 'Not your attempt.');
        }
    }

    protected function resultSummary(QuizAttempt $attempt): array
    {
        $answers = $attempt->answers()->get()->keyBy('question_id');

        // If this attempt earned the student a certificate, surface it in the response so
        // the frontend result page can show a "Your certificate is ready" call-to-action.
        $cert = null;
        if ($attempt->passed && $attempt->exam_type === 'final_certification') {
            $found = \App\Models\Certificate::where('quiz_attempt_id', $attempt->id)
                ->orWhere(function ($q) use ($attempt) {
                    $q->where('user_id', $attempt->user_id)
                      ->whereHas('course', function ($qq) use ($attempt) {
                          $qq->where('final_assessment_quiz_id', $attempt->quiz_id);
                      });
                })
                ->latest('issued_at')
                ->first();
            if ($found) {
                $cert = [
                    'id' => $found->uuid,
                    'cert_number' => $found->cert_number,
                    'status' => $found->status,
                ];
            }
        }

        return [
            'attempt_id' => $attempt->uuid,
            'status' => $attempt->status,
            'exam_type' => $attempt->exam_type,
            'passed' => (bool) $attempt->passed,
            'percentage' => (float) $attempt->percentage,
            'passing_mark_percentage' => (float) ($attempt->quiz->passing_mark_percentage ?? 50),
            'total_questions' => $attempt->total_questions,
            'correct_answers' => $attempt->correct_answers,
            'incorrect_answers' => $attempt->incorrect_answers,
            'unanswered' => $attempt->unanswered,
            'total_score' => $attempt->total_score,
            'max_possible_score' => $attempt->max_possible_score,
            'duration_seconds' => $attempt->duration_seconds,
            'auto_submit_reason' => $attempt->auto_submit_reason,
            'violations' => $attempt->violations ?? [],
            'completed_at' => $attempt->completed_at?->toIso8601String(),
            'certificate' => $cert,
            // Practice attempts get per-question review; mock/final only the summary.
            'review' => $attempt->exam_type === 'practice'
                ? collect($attempt->question_snapshot ?? [])->map(function ($q) use ($answers) {
                    $a = $answers[$q['question_id']] ?? null;
                    return [
                        'question_id' => $q['uuid'],
                        'text' => $q['text'],
                        'type' => $q['type'],
                        'options' => $q['options'],
                        'correct_answer' => $q['correct_answer'],
                        'my_answer' => $a?->answer,
                        'is_correct' => $a?->is_correct ?? false,
                        'explanation' => $q['explanation'] ?? null,
                        'points_earned' => (int) ($a?->points_earned ?? 0),
                        'points_possible' => (int) $q['points'],
                    ];
                })->values()
                : null,
            'quiz' => [
                'id' => $attempt->quiz->uuid,
                'name' => $attempt->quiz->name,
                'exam_type' => $attempt->quiz->exam_type,
            ],
        ];
    }
}
