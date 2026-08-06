<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class VerifyOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'identifier' => ['required', 'string'],
            'code' => ['required', 'string', 'size:6'],
            'type' => ['required', 'in:registration,login,password_reset,2fa,phone_verify,email_verify'],
        ];
    }
}
