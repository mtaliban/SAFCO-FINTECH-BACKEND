<?php

namespace App\Http\Requests\Quiz;

use Illuminate\Foundation\Http\FormRequest;

class SubmitAnswerRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'participant_id' => ['required', 'uuid', 'exists:quiz_session_participants,uuid'],
            'answer' => ['required'],
        ];
    }
}
