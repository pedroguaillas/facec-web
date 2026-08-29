<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class ForgotPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user' => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'user.required' => 'El usuario es obligatorio.',
        ];
    }
}
