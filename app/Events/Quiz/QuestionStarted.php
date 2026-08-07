<?php

namespace App\Events\Quiz;

use App\Events\BaseEvent;
use App\Models\Question;
use App\Models\QuizSession;

class QuestionStarted extends BaseEvent
{
    public function __construct(
        public readonly QuizSession $session,
        public readonly Question $question,
        public readonly int $index,
        public readonly int $total,
    ) {
        parent::__construct();
    }

    public static function eventName(): string { return 'quiz.session.question_started'; }

    public function toPayload(): array
    {
        // Options are sent WITHOUT the correct-answer flag so students can't cheat
        $options = collect($this->question->options ?? [])
            ->map(fn ($o) => [
                'id' => $o['id'] ?? null,
                'label' => $o['label'] ?? '',
                'color' => $o['color'] ?? null,
                'shape' => $o['shape'] ?? null,
            ])->all();

        return [
            'session_pin' => $this->session->pin,
            'question_id' => $this->question->uuid,
            'question_number' => $this->index + 1,
            'total_questions' => $this->total,
            'type' => $this->question->type,
            'text' => $this->question->text,
            'image_url' => $this->question->image_url,
            'options' => $options,
            'time_limit_seconds' => $this->question->time_limit_seconds,
            'points' => $this->question->points,
            'started_at' => $this->session->current_question_started_at?->toIso8601String(),
            'ends_at' => $this->session->current_question_ends_at?->toIso8601String(),
        ];
    }

    public function routingKey(): string
    {
        return "quiz/session/{$this->session->pin}/question_started";
    }
}
