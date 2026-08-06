<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class UploadProfilePictureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'picture' => ['required', 'image', 'mimes:jpeg,png,jpg,webp', 'max:5120'],
        ];
    }
}
