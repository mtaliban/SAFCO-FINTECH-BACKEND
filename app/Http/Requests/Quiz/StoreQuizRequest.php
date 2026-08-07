<?php

namespace App\Http\Requests\Quiz;

use Illuminate\Foundation\Http\FormRequest;

class StoreQuizRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'question_bank_id' => ['nullable', 'exists:question_banks,id'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'mode' => ['nullable', 'in:self_paced,live_kahoot,exam'],
            'category' => ['nullable', 'string'],
            'difficulty' => ['nullable', 'in:beginner,intermediate,advanced,expert'],
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:600'],
            'number_of_questions' => ['nullable', 'integer', 'min:1', 'max:200'],
            'passing_mark_percentage' => ['nullable', 'integer', 'min:0', 'max:100'],
            'max_attempts' => ['nullable', 'integer', 'min:1', 'max:100'],
            'default_time_per_question' => ['nullable', 'integer', 'in:5,10,15,20,30,45,60,90,120'],
            'shuffle_questions' => ['nullable', 'boolean'],
            'shuffle_options' => ['nullable', 'boolean'],
            'show_correct_after_each' => ['nullable', 'boolean'],
            'show_leaderboard' => ['nullable', 'boolean'],
            'award_bonus_for_speed' => ['nullable', 'boolean'],
        ];
    }
}
