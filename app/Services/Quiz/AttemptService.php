<?php

namespace App\Services\Quiz;

use App\Models\Question;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizAttemptAnswer;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Certificate\CertificateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates self-paced / exam quiz attempts (SRS Module 8).
 *
 *   startAttempt       — enforces exam-type limits + snapshots questions + starts timer
 *   getAttempt         — safe read-model for the taking UI (no correct answers leaked)
 *   submitAnswer       — records answer + running score (auto-grades against snapshot)
 *   completeAttempt    — finalizes, computes pass/fail
 *   expireAttemptIfDue — auto-close attempts past duration
 *   logViolation       — anti-cheat: appends to violations JSON; auto-fails on threshold
 */
class AttemptService
{
    public function __construct(
        protected ScoringService $scoring,
        protected CertificateService $certificates,
    ) {}

    /* ------------------------------------------------------------ *
     * START
     * ------------------------------------------------------------ */

    /**
     * @throws \DomainException with a clear message when the user cannot start.
     */
    public function startAttempt(User $user, Quiz $quiz): QuizAttempt
    {
        if (! $quiz->isPublished()) {
            throw new \DomainException('This quiz is not published yet.');
        }

        // Reap any expired attempts so the user isn't blocked by them
        $this->autoCloseExpired($user, $quiz);

        // Enforce system-wide max daily attempts (0 = unlimited)
        $maxDaily = (int) SystemSetting::get('quiz.max_daily_attempts', 0);
        if ($maxDaily > 0) {
            $todayCount = $quiz->attempts()
                ->where('user_id', $user->id)
                ->whereDate('started_at', now()->toDateString())
                ->count();
            if ($todayCount >= $maxDaily) {
                throw new \DomainException("Daily attempt limit of {$maxDaily} reached. Try again tomorrow.");
            }
        }

        // Block if user already has an in-progress attempt
        $active = $quiz->attempts()
            ->where('user_id', $user->id)
            ->where('status', 'in_progress')
            ->first();
        if ($active) {
            return $active;   // resume rather than error — safer UX
        }

        // Enforce attempt limit per exam_type (practice = unlimited)
        $examType = $quiz->exam_type ?? 'practice';
        if ($examType !== 'practice') {
            $cap = $examType === 'final_certification' ? 1 : (int) ($quiz->max_attempts ?? 1);
            $used = $quiz->attempts()
                ->where('user_id', $user->id)
                ->whereIn('status', ['completed', 'expired'])
                ->count();
            if ($used >= $cap) {
                throw new \DomainException(
                    $examType === 'final_certification'
                        ? 'You have already used your single certification attempt.'
                        : "You have used all {$cap} allowed attempts."
                );
            }
        }

        // Snapshot the question set for this attempt (isolates from trainer edits)
        $snapshot = $this->buildQuestionSnapshot($quiz);
        if (empty($snapshot)) {
            throw new \DomainException('This quiz has no questions to attempt.');
        }

        $attemptNumber = 1 + $quiz->attempts()->where('user_id', $user->id)->count();
        $maxPossible = array_sum(array_map(fn ($q) => (int) $q['points'], $snapshot));

        $expiresAt = null;
        if (! empty($quiz->duration_minutes)) {
            $expiresAt = now()->addMinutes((int) $quiz->duration_minutes);
        }

        return DB::transaction(function () use ($user, $quiz, $snapshot, $attemptNumber, $maxPossible, $expiresAt, $examType) {
            return QuizAttempt::create([
                'quiz_id' => $quiz->id,
                'user_id' => $user->id,
                'attempt_number' => $attemptNumber,
                'status' => 'in_progress',
                'exam_type' => $examType,
                'total_questions' => count($snapshot),
                'correct_answers' => 0,
                'incorrect_answers' => 0,
                'unanswered' => count($snapshot),
                'total_score' => 0,
                'max_possible_score' => $maxPossible,
                'percentage' => 0,
                'passed' => false,
                'started_at' => now(),
                'expires_at' => $expiresAt,
                'question_snapshot' => $snapshot,
                'violations' => [],
            ]);
        });
    }

    /**
     * Build the frozen question set for this attempt.
     *   - if quiz.randomize_from_bank_id set → pick N random from that bank
     *   - else → use attached quiz questions in stored order (or shuffled if enabled)
     *   - options are shuffled per-question when shuffle_options is on
     */
    protected function buildQuestionSnapshot(Quiz $quiz): array
    {
        $count = (int) ($quiz->number_of_questions ?? 0);

        // (a) Randomize from external bank
        if ($quiz->randomize_from_bank_id) {
            $bank = $quiz->randomize_from_bank_id;
            $rows = Question::where('question_bank_id', $bank)
                ->where('is_active', true)
                ->inRandomOrder()
                ->limit($count > 0 ? $count : 20)
                ->get();
        } else {
            // (b) Use attached questions
            $rows = $quiz->questions()->get();
            if ($quiz->shuffle_questions) $rows = $rows->shuffle();
            if ($count > 0 && $rows->count() > $count) $rows = $rows->take($count);
        }

        $snapshot = [];
        foreach ($rows as $q) {
            $options = $q->options;
            if (is_array($options) && $quiz->shuffle_options) {
                shuffle($options);
            }
            $snapshot[] = [
                'question_id' => $q->id,
                'uuid' => $q->uuid,
                'type' => $q->type,
                'text' => $q->text,
                'image_url' => $q->image_url,
                'options' => $options,
                // NOTE: correct_answer is kept in snapshot so grading is self-contained
                'correct_answer' => $q->correct_answer,
                'points' => (int) ($q->pivot->override_points ?? $q->points),
                'time_limit_seconds' => (int) ($q->pivot->override_time_seconds ?? $q->time_limit_seconds),
                'explanation' => $q->explanation,
                'metadata' => $q->metadata,
            ];
        }
        return $snapshot;
    }

    /* ------------------------------------------------------------ *
     * READ MODEL (for the taking page — no correct answers leaked)
     * ------------------------------------------------------------ */

    public function getAttemptForTaking(QuizAttempt $attempt): array
    {
        $answered = QuizAttemptAnswer::where('attempt_id', $attempt->id)
            ->pluck('answer', 'question_id');

        $questions = collect($attempt->question_snapshot ?? [])->map(function ($q) use ($answered) {
            return [
                'question_id' => $q['uuid'],
                'type' => $q['type'],
                'text' => $q['text'],
                'image_url' => $q['image_url'] ?? null,
                'options' => collect($q['options'] ?? [])
                    ->map(fn ($o) => is_array($o) ? array_intersect_key($o, array_flip(['id', 'label', 'left', 'right', 'color', 'shape'])) : $o)
                    ->values(),
                'points' => $q['points'],
                'time_limit_seconds' => $q['time_limit_seconds'],
                'my_answer' => $answered[$q['question_id']] ?? null,
            ];
        })->values();

        return [
            'attempt_id' => $attempt->uuid,
            'status' => $attempt->status,
            'exam_type' => $attempt->exam_type,
            'started_at' => $attempt->started_at?->toIso8601String(),
            'expires_at' => $attempt->expires_at?->toIso8601String(),
            'seconds_remaining' => $attempt->expires_at ? max(0, now()->diffInSeconds($attempt->expires_at, false)) : null,
            'progress' => [
                'answered' => (int) $answered->count(),
                'total' => (int) $attempt->total_questions,
            ],
            'violations_count' => is_array($attempt->violations) ? count($attempt->violations) : 0,
            'questions' => $questions,
        ];
    }

    /* ------------------------------------------------------------ *
     * SUBMIT / COMPLETE
     * ------------------------------------------------------------ */

    public function submitAnswer(QuizAttempt $attempt, string $questionUuid, mixed $answer): array
    {
        $this->assertActive($attempt);

        $snapshot = collect($attempt->question_snapshot ?? []);
        $row = $snapshot->firstWhere('uuid', $questionUuid);
        if (! $row) throw new \DomainException('Question not part of this attempt.');

        // Grade using a hydrated Question so we reuse checkAnswer() for all 6 types.
        $q = (new Question())->forceFill([
            'type' => $row['type'],
            'options' => $row['options'],
            'correct_answer' => $row['correct_answer'],
            'metadata' => $row['metadata'] ?? null,
            'points' => (int) $row['points'],
            'time_limit_seconds' => (int) $row['time_limit_seconds'],
        ]);
        $isCorrect = $q->checkAnswer($answer);
        $points = $isCorrect ? (int) $row['points'] : 0;

        return DB::transaction(function () use ($attempt, $row, $answer, $isCorrect, $points) {
            $existing = QuizAttemptAnswer::where('attempt_id', $attempt->id)
                ->where('question_id', $row['question_id'])
                ->first();

            if ($existing) {
                $delta = [
                    'was_correct' => (bool) $existing->is_correct,
                    'points_delta' => $points - (int) $existing->points_earned,
                ];
                $existing->update([
                    'answer' => is_array($answer) ? $answer : [$answer],
                    'is_correct' => $isCorrect,
                    'points_earned' => $points,
                ]);
            } else {
                $delta = ['was_correct' => null, 'points_delta' => $points];
                QuizAttemptAnswer::create([
                    'attempt_id' => $attempt->id,
                    'question_id' => $row['question_id'],
                    'answer' => is_array($answer) ? $answer : [$answer],
                    'is_correct' => $isCorrect,
                    'points_earned' => $points,
                    'response_time_ms' => 0,
                ]);
            }

            // Update aggregate counters idempotently based on delta
            $attempt->refresh();
            $newCorrect = (int) $attempt->correct_answers;
            $newIncorrect = (int) $attempt->incorrect_answers;
            $newUnanswered = (int) $attempt->unanswered;

            if ($delta['was_correct'] === null) {
                $newUnanswered = max(0, $newUnanswered - 1);
                $isCorrect ? $newCorrect++ : $newIncorrect++;
            } elseif ($delta['was_correct'] !== $isCorrect) {
                $isCorrect ? ($newCorrect++ && $newIncorrect--) : ($newIncorrect++ && $newCorrect--);
            }

            $newScore = (int) $attempt->total_score + $delta['points_delta'];
            $newPct = $attempt->max_possible_score > 0
                ? round($newScore / $attempt->max_possible_score * 100, 2)
                : 0;

            $attempt->update([
                'correct_answers' => $newCorrect,
                'incorrect_answers' => $newIncorrect,
                'unanswered' => $newUnanswered,
                'total_score' => $newScore,
                'percentage' => $newPct,
            ]);

            return [
                'is_correct' => $isCorrect,
                'points_earned' => $points,
                'progress' => [
                    'answered' => $newCorrect + $newIncorrect,
                    'total' => (int) $attempt->total_questions,
                ],
                'total_score' => $newScore,
                'percentage' => $newPct,
            ];
        });
    }

    public function completeAttempt(QuizAttempt $attempt, ?string $reason = null): QuizAttempt
    {
        if ($attempt->status !== 'in_progress') return $attempt;

        $finished = DB::transaction(function () use ($attempt, $reason) {
            $quiz = $attempt->quiz;
            $duration = (int) $attempt->started_at?->diffInSeconds(now());
            $percentage = (float) $attempt->percentage;
            $systemDefault = (float) SystemSetting::get('quiz.default_pass_score', 60);
            $passed = $percentage >= (float) ($quiz->passing_mark_percentage ?? $systemDefault);

            $attempt->update([
                'status' => $reason ? 'expired' : 'completed',
                'completed_at' => now(),
                'duration_seconds' => $duration,
                'passed' => $passed,
                'auto_submit_reason' => $reason,
            ]);

            // Bump quiz aggregate stats
            $newTotalPlays = (int) $quiz->total_plays + 1;
            $currentAvg = (float) $quiz->avg_score;
            $newAvg = $currentAvg > 0
                ? round(($currentAvg * ($newTotalPlays - 1) + $percentage) / $newTotalPlays, 2)
                : $percentage;
            $quiz->update(['total_plays' => $newTotalPlays, 'avg_score' => $newAvg]);

            return $attempt->fresh();
        });

        // SRS Module 10 — auto-issue a certificate when a final_certification attempt is passed.
        // Wrapped in its own try so a cert failure never rolls back the completed attempt.
        if ($finished->passed && $finished->quiz?->exam_type === 'final_certification') {
            try {
                $this->certificates->issueForAttempt($finished);
            } catch (\Throwable $e) {
                Log::warning('Certificate auto-issue failed', [
                    'attempt_id' => $finished->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $finished;
    }

    public function expireAttemptIfDue(QuizAttempt $attempt): QuizAttempt
    {
        if ($attempt->isExpired()) {
            return $this->completeAttempt($attempt, 'duration_exceeded');
        }
        return $attempt;
    }

    protected function autoCloseExpired(User $user, Quiz $quiz): void
    {
        $stale = $quiz->attempts()
            ->where('user_id', $user->id)
            ->where('status', 'in_progress')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->get();
        foreach ($stale as $s) $this->completeAttempt($s, 'duration_exceeded');
    }

    /* ------------------------------------------------------------ *
     * ANTI-CHEAT
     * ------------------------------------------------------------ */

    public function logViolation(QuizAttempt $attempt, string $type, array $meta = []): QuizAttempt
    {
        $violations = is_array($attempt->violations) ? $attempt->violations : [];
        $violations[] = [
            'type' => $type,
            'at' => now()->toIso8601String(),
            'meta' => $meta,
        ];
        $attempt->update(['violations' => $violations]);

        $threshold = (int) ($attempt->quiz->anti_cheat_settings['max_violations'] ?? 0);
        if ($threshold > 0 && count($violations) >= $threshold && $attempt->status === 'in_progress') {
            $this->completeAttempt($attempt, 'violations_threshold');
        }

        return $attempt->fresh();
    }

    /* ------------------------------------------------------------ */

    protected function assertActive(QuizAttempt $attempt): void
    {
        if ($attempt->status !== 'in_progress') {
            throw new \DomainException('Attempt is no longer active.');
        }
        if ($attempt->isExpired()) {
            $this->completeAttempt($attempt, 'duration_exceeded');
            throw new \DomainException('Attempt has expired.');
        }
    }
}
