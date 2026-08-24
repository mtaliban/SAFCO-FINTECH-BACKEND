<?php

namespace App\Http\Requests\Quiz;

use Illuminate\Foundation\Http\FormRequest;

class StoreQuizRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    /**
     * Normalise exam_type-driven fields BEFORE validation so:
     *   - final_certification always resolves to exactly 1 attempt
     *   - practice unlocks unlimited (max_attempts sent will be preserved but ignored at attempt-time)
     */
    protected function prepareForValidation(): void
    {
        if ($this->input('exam_type') === 'final_certification') {
            $this->merge(['max_attempts' => 1]);
        }
    }

    public function rules(): array
    {
        return [
            'question_bank_id' => ['nullable', 'exists:question_banks,id'],
            'randomize_from_bank_id' => ['nullable', 'exists:question_banks,id'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'mode' => ['nullable', 'in:self_paced,live_kahoot,exam'],
            'exam_type' => ['nullable', 'in:practice,mock,final_certification'],
            'anti_cheat_settings' => ['nullable', 'array'],
            'anti_cheat_settings.browser_lock' => ['sometimes', 'boolean'],
            'anti_cheat_settings.disable_copy_paste' => ['sometimes', 'boolean'],
            'anti_cheat_settings.disable_right_click' => ['sometimes', 'boolean'],
            'anti_cheat_settings.tab_switch_limit' => ['sometimes', 'integer', 'min:0', 'max:20'],
            'anti_cheat_settings.max_violations' => ['sometimes', 'integer', 'min:0', 'max:20'],
            'anti_cheat_settings.webcam_required' => ['sometimes', 'boolean'],
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
            'allow_late_join' => ['nullable', 'boolean'],
        ];
    }
}
