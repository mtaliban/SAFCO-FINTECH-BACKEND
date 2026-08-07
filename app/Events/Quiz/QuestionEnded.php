<?php

namespace App\Events\Quiz;

use App\Events\BaseEvent;
use App\Models\Question;
use App\Models\QuizSession;

class QuestionEnded extends BaseEvent
{
    public function __construct(
        public readonly QuizSession $session,
        public readonly Question $question,
        public readonly array $stats,
    ) {
        parent::__construct();
    }

    public static function eventName(): string { return 'quiz.session.question_ended'; }

    public function toPayload(): array
    {
        return [
            'session_pin' => $this->session->pin,
            'question_id' => $this->question->uuid,
            'correct_answer' => $this->question->correct_answer,
            'explanation' => $this->question->explanation,
            'stats' => $this->stats,
        ];
    }

    public function routingKey(): string
    {
        return "quiz/session/{$this->session->pin}/question_ended";
    }
}
