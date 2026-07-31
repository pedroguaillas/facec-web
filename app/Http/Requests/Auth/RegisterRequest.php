<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'user_type_id' => $this->user_type_id ?? 2,
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:4'],
            'user' => ['required', 'string', 'unique:users,user'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'min:6'],
            'user_type_id' => ['required', 'exists:user_types,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'El nombre es obligatorio.',
            'name.min' => 'El nombre debe tener al menos 4 caracteres.',
            'user.required' => 'El usuario es obligatorio.',
            'user.unique' => 'Este nombre de usuario ya está en uso.',
            'email.required' => 'El correo electrónico es obligatorio.',
            'email.email' => 'El formato del correo electrónico no es válido.',
            'email.unique' => 'Este correo electrónico ya está registrado.',
            'password.required' => 'La contraseña es obligatoria.',
            'password.min' => 'La contraseña debe tener al menos 6 caracteres.',
            'user_type_id.exists' => 'El tipo de usuario seleccionado no es válido.',
        ];
    }
}
