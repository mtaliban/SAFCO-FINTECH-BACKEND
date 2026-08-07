<?php

namespace App\Http\Requests\Quiz;

use Illuminate\Foundation\Http\FormRequest;

class JoinSessionRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'pin' => ['required', 'string', 'size:6'],
            'nickname' => ['required', 'string', 'min:1', 'max:30', 'regex:/^[\p{L}\p{N} _\-]+$/u'],
            'avatar_url' => ['nullable', 'url'],
            'team_name' => ['nullable', 'string', 'max:50'],
        ];
    }

    public function messages(): array
    {
        return [
            'nickname.regex' => 'Nickname can only contain letters, numbers, spaces, underscores or hyphens.',
        ];
    }
}
