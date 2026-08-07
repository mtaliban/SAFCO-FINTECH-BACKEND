<?php

namespace App\Http\Requests\Quiz;

use App\Models\Question;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreQuestionRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(Question::TYPES)],
            'text' => ['required', 'string', 'max:5000'],
            'explanation' => ['nullable', 'string', 'max:2000'],
            'image_url' => ['nullable', 'url'],
            'audio_url' => ['nullable', 'url'],
            'video_url' => ['nullable', 'url'],
            'options' => ['nullable', 'array', 'max:8'],
            'options.*.label' => ['required_with:options', 'string', 'max:500'],
            'correct_answer' => ['nullable'],
            'points' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'time_limit_seconds' => ['nullable', 'integer', 'in:5,10,15,20,30,45,60,90,120,240'],
            'difficulty' => ['nullable', 'in:easy,medium,hard'],
            'tags' => ['nullable', 'array'],
        ];
    }
}
