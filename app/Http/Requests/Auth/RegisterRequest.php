<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            // Un RUC solo puede registrarse una vez: una prueba gratuita por RUC.
            'ruc' => ['required', 'string', 'digits:13', 'unique:companies,ruc'],
            // Dirección del establecimiento 001 (dirEstablecimiento en el XML).
            'address' => ['required', 'string', 'max:300'],
            'phone' => ['nullable', 'string', 'max:15'],
            'user' => ['required', 'string', 'alpha_dash', 'min:4', 'max:50', 'unique:users,user'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ruc.required' => 'El número de RUC es obligatorio.',
            'ruc.digits' => 'El RUC debe tener exactamente 13 dígitos numéricos.',
            'ruc.unique' => 'Este RUC ya está registrado. Si es tuyo, inicia sesión o recupera tu contraseña.',
            'address.required' => 'La dirección del establecimiento es obligatoria.',
            'user.required' => 'El usuario es obligatorio.',
            'user.alpha_dash' => 'El usuario solo puede tener letras, números, guiones y guiones bajos.',
            'user.unique' => 'Este usuario ya está en uso.',
            'email.required' => 'El correo es obligatorio.',
            'email.email' => 'El correo no es válido.',
            'email.unique' => 'Este correo ya está registrado.',
            'password.required' => 'La contraseña es obligatoria.',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres.',
            'password.confirmed' => 'Las contraseñas no coinciden.',
        ];
    }
}
