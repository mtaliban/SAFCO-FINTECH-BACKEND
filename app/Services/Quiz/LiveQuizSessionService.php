<?php

namespace App\Services\Quiz;

use App\Events\Quiz\AnswerSubmitted;
use App\Events\Quiz\LeaderboardUpdated;
use App\Events\Quiz\ParticipantJoined;
use App\Events\Quiz\QuestionEnded;
use App\Events\Quiz\QuestionStarted;
use App\Events\Quiz\QuizSessionCompleted;
use App\Events\Quiz\QuizSessionCreated;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\QuizSession;
use App\Models\QuizSessionAnswer;
use App\Models\QuizSessionParticipant;
use App\Models\User;
use App\Services\EventBus\EventDispatcher;
use App\Services\EventBus\MqttPublisher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrates the entire lifetime of a live Kahoot-style quiz session:
 *   host creates -> students join with PIN -> host starts each question ->
 *   students submit answers -> host reveals correct answer -> leaderboard ->
 *   next question -> ... -> final podium.
 *
 * Every state change dispatches a domain event which:
 *   (a) is stored in the outbox for RabbitMQ + MQTT publishing, AND
 *   (b) is immediately broadcast on MQTT so live clients see it in real time.
 */
class LiveQuizSessionService
{
    public function __construct(
        protected EventDispatcher $events,
        protected MqttPublisher $mqtt,
        protected PinGenerator $pinGen,
        protected ScoringService $scoring,
    ) {
    }

    /* ------------------------------------------------------------ *
     *  HOST FLOW
     * ------------------------------------------------------------ */

    public function createSession(User $host, Quiz $quiz, string $mode = 'classic'): QuizSession
    {
        if (! $quiz->isPublished()) {
            throw new \DomainException('Quiz must be published before hosting a live session.');
        }

        $questionCount = $quiz->questions()->count();
        if ($questionCount === 0) {
            throw new \DomainException('Quiz has no questions.');
        }

        return DB::transaction(function () use ($host, $quiz, $mode, $questionCount) {
            $session = QuizSession::create([
                'pin' => $this->pinGen->generate(),
                'quiz_id' => $quiz->id,
                'host_id' => $host->id,
                'mode' => $mode,
                'status' => 'waiting',
                'total_questions' => $questionCount,
            ]);

            $this->events->dispatch(
                new QuizSessionCreated($session),
                aggregateType: QuizSession::class,
                aggregateId: $session->id
            );
            $this->broadcast($session, 'created', new QuizSessionCreated($session));

            return $session->fresh(['quiz', 'host']);
        });
    }

    public function startNextQuestion(QuizSession $session): array
    {
        if ($session->status === 'completed') {
            throw new \DomainException('Session already completed.');
        }

        $quiz = $session->quiz()->with('questions')->first();
        $questions = $quiz->questions;

        // Move index forward (or 0 for the first question)
        $nextIndex = $session->status === 'waiting' ? 0 : $session->current_question_index + 1;

        if ($nextIndex >= $questions->count()) {
            return $this->complete($session);
        }

        /** @var Question $question */
        $question = $questions[$nextIndex];
        $timeLimit = $question->pivot->override_time_seconds ?? $question->time_limit_seconds;

        $session->update([
            'status' => 'question_active',
            'current_question_index' => $nextIndex,
            'current_question_id' => $question->id,
            'current_question_started_at' => now(),
            'current_question_ends_at' => now()->addSeconds($timeLimit),
            'started_at' => $session->started_at ?? now(),
        ]);

        // Clear answer count cache for this question
        Cache::forget("session:{$session->pin}:answers:{$question->id}");

        $event = new QuestionStarted(
            $session->fresh(),
            $question,
            $nextIndex,
            $questions->count()
        );
        $this->events->dispatch($event, QuizSession::class, $session->id);
        $this->broadcast($session, 'question_started', $event);

        return $event->toPayload();
    }

    public function endCurrentQuestion(QuizSession $session): array
    {
        $question = $session->currentQuestion;
        if (! $question) {
            throw new \DomainException('No active question to end.');
        }

        $session->update(['status' => 'question_ended']);

        $stats = $this->computeQuestionStats($session, $question);

        $event = new QuestionEnded($session, $question, $stats);
        $this->events->dispatch($event, QuizSession::class, $session->id);
        $this->broadcast($session, 'question_ended', $event);

        // Push leaderboard right after answer reveal
        $this->broadcastLeaderboard($session);

        return [
            'question' => [
                'id' => $question->uuid,
                'text' => $question->text,
                'type' => $question->type,
                'options' => $question->options,
                'explanation' => $question->explanation,
            ],
            'correct_answer' => $question->correct_answer,
            'stats' => $stats,
        ];
    }

    public function complete(QuizSession $session): array
    {
        $leaderboard = $this->getLeaderboard($session, 100);

        $session->update([
            'status' => 'completed',
            'ended_at' => now(),
            'final_leaderboard' => $leaderboard,
        ]);

        $event = new QuizSessionCompleted($session->fresh());
        $this->events->dispatch($event, QuizSession::class, $session->id);
        $this->broadcast($session, 'completed', $event);

        return [
            'session_pin' => $session->pin,
            'final_leaderboard' => $leaderboard,
        ];
    }

    /* ------------------------------------------------------------ *
     *  STUDENT FLOW
     * ------------------------------------------------------------ */

    public function joinByPin(string $pin, array $data): array
    {
        $session = QuizSession::where('pin', $pin)->firstOrFail();

        if (! in_array($session->status, ['waiting', 'question_active', 'question_ended', 'showing_leaderboard'], true)) {
            throw new \DomainException('This session is no longer accepting new participants.');
        }

        $nickname = trim($data['nickname']);
        if ($nickname === '') {
            throw new \InvalidArgumentException('Nickname is required.');
        }

        // Prevent duplicate nicknames within a session
        if ($session->participants()->where('nickname', $nickname)->exists()) {
            throw new \DomainException("Nickname '{$nickname}' is already taken in this session.");
        }

        $isLate = $session->started_at !== null && in_array($session->status, ['question_active', 'question_ended', 'showing_leaderboard'], true);

        $participant = DB::transaction(function () use ($session, $nickname, $data, $isLate) {
            $p = QuizSessionParticipant::create([
                'session_id' => $session->id,
                'user_id' => $data['user_id'] ?? null,
                'nickname' => $nickname,
                'avatar_url' => $data['avatar_url'] ?? null,
                'team_name' => $data['team_name'] ?? null,
                'ip_address' => $data['ip_address'] ?? null,
                'device_type' => $data['device_type'] ?? null,
                'joined_at' => now(),
                'last_seen_at' => now(),
                'is_late_join' => $isLate,
            ]);

            $session->increment('participant_count');
            return $p;
        });

        $event = new ParticipantJoined($participant->fresh('session'));
        $this->events->dispatch($event, QuizSession::class, $session->id);
        $this->broadcast($session, 'participant_joined', $event);

        return [
            'session' => [
                'pin' => $session->pin,
                'quiz_name' => $session->quiz->name,
                'total_questions' => $session->total_questions,
                'status' => $session->fresh()->status,
            ],
            'participant' => [
                'id' => $participant->uuid,
                'nickname' => $participant->nickname,
                'total_participants' => $session->fresh()->participant_count,
            ],
        ];
    }

    public function submitAnswer(
        QuizSession $session,
        QuizSessionParticipant $participant,
        mixed $answer,
    ): array {
        if ($session->status !== 'question_active') {
            throw new \DomainException('No active question to answer.');
        }

        $question = $session->currentQuestion;

        // Prevent double-submission
        if (QuizSessionAnswer::where('session_id', $session->id)
            ->where('participant_id', $participant->id)
            ->where('question_id', $question->id)
            ->exists()) {
            throw new \DomainException('You have already answered this question.');
        }

        $startedAt = $session->current_question_started_at;
        // Carbon 3 preserves sign; take absolute so response time is always positive
        $responseTimeMs = (int) abs($startedAt->diffInMilliseconds(now()));

        $quizSettings = $session->quiz;
        $result = $this->scoring->score(
            $question,
            $answer,
            $responseTimeMs,
            $participant->current_streak,
            $question->pivot->override_points ?? null,
            $question->pivot->override_time_seconds ?? null,
            (bool) ($quizSettings->award_bonus_for_speed ?? true),
        );

        return DB::transaction(function () use ($session, $participant, $question, $answer, $responseTimeMs, $result) {
            $position = Cache::increment("session:{$session->pin}:answers:{$question->id}");

            $record = QuizSessionAnswer::create([
                'session_id' => $session->id,
                'participant_id' => $participant->id,
                'question_id' => $question->id,
                'answer' => is_array($answer) ? $answer : [$answer],
                'is_correct' => $result['is_correct'],
                'points_earned' => $result['total'],
                'speed_bonus' => $result['speed_bonus'],
                'streak_bonus' => $result['streak_bonus'],
                'response_time_ms' => $responseTimeMs,
                'answered_at_position' => $position,
            ]);

            // Update participant stats
            $participant->increment('total_score', $result['total']);
            if ($result['is_correct']) {
                $participant->increment('correct_answers');
                $newStreak = $participant->current_streak + 1;
                $participant->update([
                    'current_streak' => $newStreak,
                    'longest_streak' => max($participant->longest_streak, $newStreak),
                ]);
            } else {
                $participant->increment('incorrect_answers');
                $participant->update(['current_streak' => 0]);
            }

            // Rolling avg response time
            $participant->update([
                'avg_response_time_ms' => (int) (
                    ($participant->avg_response_time_ms * ($participant->correct_answers + $participant->incorrect_answers - 1)
                    + $responseTimeMs) / max(1, $participant->correct_answers + $participant->incorrect_answers)
                ),
                'last_seen_at' => now(),
            ]);

            $event = new AnswerSubmitted($record);
            $this->events->dispatch($event, QuizSession::class, $session->id);
            // Answer notifications go ONLY to the host (via a private topic)
            $this->mqtt->publishRaw(
                "safco/lms/quiz/session/{$session->pin}/host/answers",
                $event->toPayload()
            );

            return [
                'is_correct' => $result['is_correct'],
                'points_earned' => $result['total'],
                'speed_bonus' => $result['speed_bonus'],
                'streak_bonus' => $result['streak_bonus'],
                'current_streak' => $participant->fresh()->current_streak,
                'total_score' => $participant->fresh()->total_score,
                'response_time_ms' => $responseTimeMs,
                'answered_at_position' => $position,
                'correct_answer' => $question->correct_answer,
            ];
        });
    }

    /* ------------------------------------------------------------ *
     *  READ MODELS
     * ------------------------------------------------------------ */

    public function getLeaderboard(QuizSession $session, int $limit = 10): array
    {
        return $session->participants()
            ->orderByDesc('total_score')
            ->orderBy('avg_response_time_ms')
            ->limit($limit)
            ->get()
            ->values()
            ->map(fn ($p, $i) => [
                'rank' => $i + 1,
                'participant_id' => $p->uuid,
                'nickname' => $p->nickname,
                'avatar_url' => $p->avatar_url,
                'total_score' => $p->total_score,
                'correct_answers' => $p->correct_answers,
                'incorrect_answers' => $p->incorrect_answers,
                'current_streak' => $p->current_streak,
                'longest_streak' => $p->longest_streak,
                'is_late_join' => (bool) $p->is_late_join,
            ])
            ->all();
    }

    public function broadcastLeaderboard(QuizSession $session): void
    {
        $board = $this->getLeaderboard($session, 10);
        $event = new LeaderboardUpdated($session, $board);
        $this->events->dispatch($event, QuizSession::class, $session->id);
        $this->broadcast($session, 'leaderboard', $event);
    }

    protected function computeQuestionStats(QuizSession $session, Question $question): array
    {
        $answers = QuizSessionAnswer::where('session_id', $session->id)
            ->where('question_id', $question->id)
            ->get();

        $total = $answers->count();
        $correct = $answers->where('is_correct', true)->count();

        // Distribution across options (for multiple choice)
        $distribution = [];
        foreach ($answers as $a) {
            $key = is_array($a->answer) ? implode(',', $a->answer) : (string) $a->answer;
            $distribution[$key] = ($distribution[$key] ?? 0) + 1;
        }

        return [
            'total_answers' => $total,
            'correct_count' => $correct,
            'incorrect_count' => $total - $correct,
            'correct_rate_percent' => $total ? round($correct * 100 / $total, 1) : 0,
            'avg_response_time_ms' => $total ? (int) $answers->avg('response_time_ms') : 0,
            'distribution' => $distribution,
        ];
    }

    /* ------------------------------------------------------------ *
     *  BROADCAST HELPER (MQTT direct push)
     * ------------------------------------------------------------ */

    protected function broadcast(QuizSession $session, string $sub, mixed $event): void
    {
        $topic = "safco/lms/quiz/session/{$session->pin}/{$sub}";
        $payload = method_exists($event, 'envelope') ? $event->envelope() : (array) $event;
        $this->mqtt->publishRaw($topic, $payload);
    }
}
