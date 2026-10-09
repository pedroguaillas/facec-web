<?php

namespace App\Http\Requests\Company;

use App\StaticClasses\PlanTypes;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'plan' => ['required', 'string', Rule::in(PlanTypes::ALL)],
            // GRATUITO sin fecha = trial de 1 mes; los demás planes la exigen.
            'ends_at' => ['required_unless:plan,'.PlanTypes::GRATUITO, 'nullable', 'date', 'after:today'],
            // Monto pagado por el período; GRATUITO sin monto = 0.
            'amount' => ['required_unless:plan,'.PlanTypes::GRATUITO, 'nullable', 'numeric', 'min:0', 'max:99999999.99'],
        ];
    }
}
