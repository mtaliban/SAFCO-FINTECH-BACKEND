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
