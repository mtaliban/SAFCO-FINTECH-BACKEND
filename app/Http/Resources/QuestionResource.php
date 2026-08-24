<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QuestionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'type' => $this->type,
            'text' => $this->text,
            'explanation' => $this->explanation,
            'image_url' => $this->image_url,
            'options' => $this->options,
            'correct_answer' => $this->when(
                $request->user()?->hasAnyRole(['system_admin', 'trainer']),
                $this->correct_answer
            ),
            'metadata' => $this->when(
                $request->user()?->hasAnyRole(['system_admin', 'trainer']),
                $this->metadata
            ),
            'points' => $this->points,
            'time_limit_seconds' => $this->time_limit_seconds,
            'difficulty' => $this->difficulty,
            'tags' => $this->tags,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
