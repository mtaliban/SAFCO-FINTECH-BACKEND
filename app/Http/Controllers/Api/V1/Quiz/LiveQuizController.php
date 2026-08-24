<?php

namespace App\Http\Controllers\Api\V1\Quiz;

use App\Http\Controllers\Controller;
use App\Http\Requests\Quiz\JoinSessionRequest;
use App\Http\Requests\Quiz\SubmitAnswerRequest;
use App\Http\Resources\QuizSessionResource;
use App\Models\Quiz;
use App\Models\QuizSession;
use App\Models\QuizSessionParticipant;
use App\Services\Quiz\LiveQuizSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoints for the LIVE Kahoot-style quiz engine.
 *
 * HOST endpoints (auth required, role: trainer/admin):
 *   POST /quizzes/{uuid}/host             - start a live session (returns PIN)
 *   POST /sessions/{uuid}/start-question  - advance to next question
 *   POST /sessions/{uuid}/end-question    - reveal correct answer + leaderboard
 *   POST /sessions/{uuid}/complete        - finalize + broadcast podium
 *   GET  /sessions/{uuid}/leaderboard     - current top 10
 *
 * STUDENT endpoints (public — no auth):
 *   POST /play/join                       - join a session with PIN + nickname
 *   GET  /play/session/{pin}              - session state (for lobby)
 *   POST /play/session/{pin}/answer       - submit an answer
 */
class LiveQuizController extends Controller
{
    public function __construct(protected LiveQuizSessionService $live) {}

    /* ---------------- HOST ---------------- */

    public function hostStart(Quiz $quiz, Request $request): JsonResponse
    {
        try {
            $session = $this->live->createSession(
                $request->user(),
                $quiz,
                $request->input('mode', 'classic')
            );
            return $this->success(new QuizSessionResource($session), 'Live session started', 201);
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function hostStartQuestion(QuizSession $session): JsonResponse
    {
        try {
            $payload = $this->live->startNextQuestion($session);
            return $this->success($payload);
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function hostEndQuestion(QuizSession $session): JsonResponse
    {
        try {
            $stats = $this->live->endCurrentQuestion($session);
            return $this->success($stats);
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function hostComplete(QuizSession $session): JsonResponse
    {
        $final = $this->live->complete($session);
        return $this->success($final, 'Session completed');
    }

    public function leaderboard(QuizSession $session, Request $request): JsonResponse
    {
        return $this->success(
            $this->live->getLeaderboard($session, (int) $request->query('limit', 10))
        );
    }

    /** GET /api/v1/sessions/{uuid}/participants — full lobby list (host view) */
    public function participants(QuizSession $session): JsonResponse
    {
        $rows = $session->participants()
            ->latest('joined_at')
            ->get()
            ->map(fn ($p) => [
                'id' => $p->uuid,
                'nickname' => $p->nickname,
                'avatar_url' => $p->avatar_url,
                'total_score' => $p->total_score,
                'correct_answers' => $p->correct_answers,
                'is_late_join' => (bool) $p->is_late_join,
                'is_connected' => (bool) $p->is_connected,
                'joined_at' => $p->joined_at?->toIso8601String(),
            ]);

        return $this->success([
            'count' => $rows->count(),
            'participants' => $rows,
        ]);
    }

    /** GET /api/v1/sessions/{uuid}/answer-count — live "X/Y answered" during question_active */
    public function answerCount(QuizSession $session): JsonResponse
    {
        $answered = 0;
        if ($session->current_question_id) {
            $answered = \App\Models\QuizSessionAnswer::where('session_id', $session->id)
                ->where('question_id', $session->current_question_id)
                ->count();
        }
        return $this->success([
            'answered' => $answered,
            'total' => (int) $session->participant_count,
            'question_index' => (int) $session->current_question_index,
        ]);
    }

    /* ---------------- STUDENT ---------------- */

    /**
     * POST /api/v1/play/join
     * Public — no auth needed. Students identify with just PIN + nickname.
     */
    public function playJoin(JoinSessionRequest $request): JsonResponse
    {
        try {
            $result = $this->live->joinByPin($request->pin, [
                'nickname' => $request->nickname,
                'avatar_url' => $request->avatar_url,
                'team_name' => $request->team_name,
                'user_id' => $request->user()?->id,
                'ip_address' => $request->ip(),
                'device_type' => $this->detectDevice($request->userAgent()),
            ]);
            return $this->success($result, 'Joined session');
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->error('Session PIN not found.', 404);
        }
    }

    /** GET /api/v1/play/session/{pin} */
    public function playSessionState(string $pin): JsonResponse
    {
        $session = QuizSession::where('pin', $pin)->firstOrFail();
        return $this->success([
            'pin' => $session->pin,
            'quiz_name' => $session->quiz->name,
            'status' => $session->status,
            'participant_count' => $session->participant_count,
            'total_questions' => $session->total_questions,
            'current_question_index' => $session->current_question_index,
            'current_question_ends_at' => $session->current_question_ends_at?->toIso8601String(),
            'realtime_topic' => $session->realtimeTopic(),
            // Only surfaced after the host completes the session.
            'final_leaderboard' => $session->status === 'completed'
                ? ($session->final_leaderboard ?? [])
                : null,
        ]);
    }

    /** GET /api/v1/play/session/{pin}/current-question
     *  Frontend polls this to render the active question on the player screen.
     *  Answers are stripped so participants can't see the correct choice.
     */
    public function playCurrentQuestion(string $pin): JsonResponse
    {
        $session = QuizSession::where('pin', $pin)->firstOrFail();

        if (!$session->current_question_id || $session->status !== 'question_active') {
            return $this->success(null);
        }

        $q = $session->currentQuestion;
        if (!$q) return $this->success(null);

        return $this->success([
            'question_id' => $q->uuid,
            'question_number' => (int) $session->current_question_index + 1,
            'total_questions' => (int) $session->total_questions,
            'type' => $q->type,
            'text' => $q->text,
            'image_url' => $q->image_url,
            'options' => collect($q->options ?? [])
                ->map(fn ($o) => ['id' => $o['id'] ?? null, 'label' => $o['label'] ?? '', 'color' => $o['color'] ?? null, 'shape' => $o['shape'] ?? null])
                ->values(),
            'time_limit_seconds' => (int) $q->time_limit_seconds,
            'ends_at' => $session->current_question_ends_at?->toIso8601String(),
        ]);
    }

    /** POST /api/v1/play/session/{pin}/answer */
    public function playSubmitAnswer(string $pin, SubmitAnswerRequest $request): JsonResponse
    {
        $session = QuizSession::where('pin', $pin)->firstOrFail();
        $participant = QuizSessionParticipant::where('uuid', $request->participant_id)
            ->where('session_id', $session->id)
            ->firstOrFail();

        try {
            $result = $this->live->submitAnswer($session, $participant, $request->answer);
            return $this->success($result);
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    protected function detectDevice(?string $ua): string
    {
        if (! $ua) return 'unknown';
        if (preg_match('/Mobile|Android|iPhone/i', $ua)) return 'mobile';
        if (preg_match('/iPad|Tablet/i', $ua)) return 'tablet';
        return 'desktop';
    }
}
