<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:255'],
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'email' => ['required', 'email:rfc,dns', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:20', 'unique:users,phone'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'gender' => ['nullable', 'in:male,female,other,prefer_not_to_say'],
            'position' => ['nullable', 'string', 'max:100'],
            'organization_id' => ['nullable', 'exists:organizations,id'],
            'role' => ['nullable', 'in:student,trainer,corporate_client'],
            'country' => ['nullable', 'string', 'max:100'],
            'timezone' => ['nullable', 'string', 'max:50'],
            'locale' => ['nullable', 'string', 'max:10'],
            'accept_terms' => ['required', 'accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'accept_terms.accepted' => 'You must accept the terms and conditions to register.',
            'email.unique' => 'This email is already registered.',
            'phone.unique' => 'This phone number is already registered.',
        ];
    }
}
