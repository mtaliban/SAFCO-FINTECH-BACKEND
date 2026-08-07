<?php

namespace App\Events\Quiz;

use App\Events\BaseEvent;
use App\Models\QuizSessionParticipant;

class ParticipantJoined extends BaseEvent
{
    public function __construct(public readonly QuizSessionParticipant $participant)
    {
        parent::__construct();
    }

    public static function eventName(): string { return 'quiz.session.participant_joined'; }

    public function toPayload(): array
    {
        return [
            'participant_id' => $this->participant->uuid,
            'session_id' => $this->participant->session->uuid,
            'pin' => $this->participant->session->pin,
            'nickname' => $this->participant->nickname,
            'avatar_url' => $this->participant->avatar_url,
            'total_participants' => $this->participant->session->participant_count,
            'joined_at' => $this->participant->joined_at->toIso8601String(),
        ];
    }

    public function routingKey(): string
    {
        return "quiz/session/{$this->participant->session->pin}/participant_joined";
    }
}
