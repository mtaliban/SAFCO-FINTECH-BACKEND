<?php

namespace App\Events\Quiz;

use App\Events\BaseEvent;
use App\Models\QuizSession;

class QuizSessionCreated extends BaseEvent
{
    public function __construct(public readonly QuizSession $session) { parent::__construct(); }

    public static function eventName(): string { return 'quiz.session.created'; }

    public function toPayload(): array
    {
        return [
            'session_id' => $this->session->uuid,
            'pin' => $this->session->pin,
            'quiz_id' => $this->session->quiz->uuid,
            'quiz_name' => $this->session->quiz->name,
            'host_id' => $this->session->host_id,
            'total_questions' => $this->session->total_questions,
            'mode' => $this->session->mode,
            'created_at' => $this->session->created_at->toIso8601String(),
        ];
    }

    public function routingKey(): string { return "quiz/session/{$this->session->pin}/created"; }
}
