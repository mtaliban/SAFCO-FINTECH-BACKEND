<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QuizSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'pin' => $this->pin,
            'quiz' => [
                'id' => $this->quiz->uuid,
                'name' => $this->quiz->name,
            ],
            'mode' => $this->mode,
            'status' => $this->status,
            'total_questions' => $this->total_questions,
            'participant_count' => $this->participant_count,
            'current_question_index' => $this->current_question_index,
            'current_question_started_at' => $this->current_question_started_at?->toIso8601String(),
            'current_question_ends_at' => $this->current_question_ends_at?->toIso8601String(),
            'realtime_topic' => $this->realtimeTopic(),
            'started_at' => $this->started_at?->toIso8601String(),
            'ended_at' => $this->ended_at?->toIso8601String(),
        ];
    }
}
