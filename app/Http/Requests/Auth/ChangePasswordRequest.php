<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', 'different:current_password', Password::min(8)],
        ];
    }

    public function messages(): array
    {
        return [
            'current_password.required' => 'La contraseña actual es obligatoria.',
            'password.required' => 'La nueva contraseña es obligatoria.',
            'password.confirmed' => 'La confirmación de contraseña no coincide.',
            'password.different' => 'La nueva contraseña debe ser distinta a la actual.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            /** @var User $user */
            $user = $this->user();

            if (! Hash::check((string) $this->input('current_password'), $user->password)) {
                $validator->errors()->add('current_password', 'La contraseña actual es incorrecta.');
            }
        });
    }
}
