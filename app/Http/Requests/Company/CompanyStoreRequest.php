<?php

namespace App\Http\Requests\Company;

use App\StaticClasses\PlanTypes;
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

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'ruc' => ['required', 'string', 'digits:13', 'unique:companies,ruc'],
            'company' => ['required', 'string', 'max:100'],
            'economic_activity' => ['required', 'string', 'max:100'],
            'enviroment_type' => ['required', 'integer', Rule::in([1, 2])],
            'decimal' => ['required', 'integer', 'between:2,6'],
            'rimpe' => ['sometimes', 'nullable', 'integer', Rule::in([0, 1, 2])],
            'plan' => ['required', 'string', Rule::in(PlanTypes::ALL)],
            // GRATUITO sin fecha = trial de 1 mes; los demás planes la exigen.
            'plan_ends_at' => ['required_unless:plan,'.PlanTypes::GRATUITO, 'nullable', 'date', 'after:today'],
            // Monto pagado por el período; GRATUITO sin monto = 0.
            'plan_amount' => ['required_unless:plan,'.PlanTypes::GRATUITO, 'nullable', 'numeric', 'min:0', 'max:99999999.99'],
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

            'enviroment_type.in' => 'El ambiente debe ser 1 (pruebas) o 2 (producción).',
            'decimal.between' => 'Los decimales deben estar entre 2 y 6.',
            'rimpe.in' => 'El régimen RIMPE seleccionado no es válido.',

            'plan.required' => 'El plan de suscripción es obligatorio.',
            'plan.in' => 'El plan seleccionado no es válido.',
            'plan_ends_at.required_unless' => 'La fecha de fin del plan es obligatoria.',
            'plan_ends_at.after' => 'La fecha de fin del plan debe ser posterior a hoy.',
            'plan_amount.required_unless' => 'El monto del plan es obligatorio.',
            'plan_amount.min' => 'El monto del plan no puede ser negativo.',

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
