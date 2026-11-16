<?php

namespace App\Http\Requests\Company;

use App\StaticClasses\PlanTypes;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Corrección manual de un registro del historial (fechas, plan o monto mal
 * cargados): no cierra ni abre otras suscripciones.
 */
class UpdateSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'plan' => ['required', 'string', Rule::in(PlanTypes::ALL)],
            'started_at' => ['required', 'date'],
            'ended_at' => ['required', 'date', 'after_or_equal:started_at'],
            'amount' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
        ];
    }
}
