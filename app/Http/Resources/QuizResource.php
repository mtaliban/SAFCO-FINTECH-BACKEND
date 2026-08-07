<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QuizResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'cover_image' => $this->cover_image,
            'mode' => $this->mode,
            'category' => $this->category,
            'difficulty' => $this->difficulty,
            'duration_minutes' => $this->duration_minutes,
            'number_of_questions' => $this->number_of_questions,
            'passing_mark_percentage' => $this->passing_mark_percentage,
            'max_attempts' => $this->max_attempts,
            'default_time_per_question' => $this->default_time_per_question,
            'status' => $this->status,
            'settings' => [
                'shuffle_questions' => $this->shuffle_questions,
                'shuffle_options' => $this->shuffle_options,
                'show_correct_after_each' => $this->show_correct_after_each,
                'show_leaderboard' => $this->show_leaderboard,
                'award_bonus_for_speed' => $this->award_bonus_for_speed,
                'allow_late_join' => $this->allow_late_join,
            ],
            'stats' => [
                'total_plays' => $this->total_plays,
                'avg_score' => $this->avg_score,
            ],
            'questions' => QuestionResource::collection($this->whenLoaded('questions')),
            'creator' => $this->whenLoaded('creator', fn () => [
                'id' => $this->creator->uuid,
                'name' => $this->creator->profile?->full_name ?? $this->creator->email,
            ]),
            'published_at' => $this->published_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
