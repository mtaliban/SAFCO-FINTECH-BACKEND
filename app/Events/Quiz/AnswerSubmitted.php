<?php

namespace App\Events\Quiz;

use App\Events\BaseEvent;
use App\Models\QuizSessionAnswer;

class AnswerSubmitted extends BaseEvent
{
    public function __construct(public readonly QuizSessionAnswer $answer)
    {
        parent::__construct();
    }

    public static function eventName(): string { return 'quiz.session.answer_submitted'; }

    public function toPayload(): array
    {
        return [
            'session_id' => $this->answer->session_id,
            'session_pin' => $this->answer->session->pin ?? null,
            'participant_id' => $this->answer->participant->uuid,
            'participant_nickname' => $this->answer->participant->nickname,
            'question_id' => $this->answer->question_id,
            'response_time_ms' => $this->answer->response_time_ms,
            'answered_at_position' => $this->answer->answered_at_position,
            'is_correct' => $this->answer->is_correct,
            'points_earned' => $this->answer->points_earned,
        ];
    }

    public function routingKey(): string
    {
        $pin = $this->answer->session->pin ?? 'unknown';
        return "quiz/session/{$pin}/answer_submitted";
    }
}
