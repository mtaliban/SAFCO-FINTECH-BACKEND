<?php

namespace App\Events\Quiz;

use App\Events\BaseEvent;
use App\Models\QuizSession;

class QuizSessionCompleted extends BaseEvent
{
    public function __construct(public readonly QuizSession $session) { parent::__construct(); }

    public static function eventName(): string { return 'quiz.session.completed'; }

    public function toPayload(): array
    {
        return [
            'session_pin' => $this->session->pin,
            'session_id' => $this->session->uuid,
            'quiz_name' => $this->session->quiz->name ?? null,
            'total_participants' => $this->session->participant_count,
            'total_questions' => $this->session->total_questions,
            'final_leaderboard' => $this->session->final_leaderboard,
            'duration_seconds' => $this->session->ended_at?->diffInSeconds($this->session->started_at),
            'completed_at' => $this->session->ended_at?->toIso8601String(),
        ];
    }

    public function routingKey(): string
    {
        return "quiz/session/{$this->session->pin}/completed";
    }
}
