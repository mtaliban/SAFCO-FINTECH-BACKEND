<?php

namespace App\Events\Quiz;

use App\Events\BaseEvent;
use App\Models\QuizSession;

class LeaderboardUpdated extends BaseEvent
{
    public function __construct(
        public readonly QuizSession $session,
        public readonly array $leaderboard,
    ) {
        parent::__construct();
    }

    public static function eventName(): string { return 'quiz.session.leaderboard_updated'; }

    public function toPayload(): array
    {
        return [
            'session_pin' => $this->session->pin,
            'question_number' => $this->session->current_question_index + 1,
            'total_questions' => $this->session->total_questions,
            'leaderboard' => $this->leaderboard,
        ];
    }

    public function routingKey(): string
    {
        return "quiz/session/{$this->session->pin}/leaderboard";
    }
}
