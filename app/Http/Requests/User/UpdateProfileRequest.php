<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'full_name' => ['sometimes', 'string', 'max:255'],
            'first_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'middle_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'gender' => ['sometimes', 'nullable', 'in:male,female,other,prefer_not_to_say'],
            'date_of_birth' => ['sometimes', 'nullable', 'date', 'before:today'],
            'nationality' => ['sometimes', 'nullable', 'string', 'max:100'],
            'national_id' => ['sometimes', 'nullable', 'string', 'max:50'],
            'position' => ['sometimes', 'nullable', 'string', 'max:100'],
            'department' => ['sometimes', 'nullable', 'string', 'max:100'],
            'bio' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'address_line1' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address_line2' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'region' => ['sometimes', 'nullable', 'string', 'max:100'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'country' => ['sometimes', 'nullable', 'string', 'max:100'],
            'social_links' => ['sometimes', 'nullable', 'array'],
            'social_links.linkedin' => ['sometimes', 'nullable', 'url'],
            'social_links.twitter' => ['sometimes', 'nullable', 'url'],
            'preferences' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
