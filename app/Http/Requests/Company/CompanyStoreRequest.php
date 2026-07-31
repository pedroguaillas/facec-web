<?php

namespace App\Http\Requests\Company;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CompanyStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'enviroment_type' => $this->enviroment_type ?: 2,
            'decimal' => $this->decimal ?: 6,
            'economic_activity' => $this->economic_activity ?: 'otros',
            'user_type_id' => $this->user_type_id ?: 2,
        ]);
    }

    public function rules(): array
    {
        return [
            'ruc' => ['required', 'string', 'digits:13', 'unique:companies,ruc'],
            'company' => ['required', 'string', 'max:100'],
            'economic_activity' => ['required', 'string', 'max:100'],
            'user' => ['required', 'string', 'unique:users,user'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'min:6'],
            'user_type_id' => [
                'required',
                'integer',
                Rule::exists('user_types', 'id'),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'ruc.required' => 'El número de RUC es obligatorio.',
            'ruc.digits' => 'El RUC debe tener exactamente 13 dígitos numéricos.',
            'ruc.unique' => 'Este RUC ya se encuentra registrado en el sistema.',

            'company.required' => 'El nombre de la empresa es obligatorio.',
            'company.max' => 'El nombre de la empresa no puede superar los 100 caracteres.',
            'economic_activity.required' => 'La actividad económica es obligatoria.',

            'user.required' => 'El nombre de usuario es obligatorio.',
            'user.unique' => 'Este nombre de usuario ya está en uso.',

            'email.required' => 'El correo electrónico es obligatorio.',
            'email.email' => 'El formato del correo electrónico no es válido.',
            'email.unique' => 'Este correo electrónico ya está registrado.',

            'password.required' => 'La contraseña es obligatoria.',
            'password.min' => 'La contraseña debe tener al menos 6 caracteres.',

            'user_type_id.required' => 'El tipo de usuario es obligatorio.',
            'user_type_id.exists' => 'El tipo de usuario seleccionado no es válido.',
        ];
    }
}
