<?php

namespace App\Http\Requests\EmisionPoint;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EmisionPointUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        $emisionPoint = $this->route('emisionPoint');

        if ($user->isAdmin()) {
            return true;
        }
        $emisionPoint->loadMissing('branch');
        $userCompanyId = $user->company->id;
        $pointCompanyId = $emisionPoint->branch->company_id;

        return $userCompanyId && (int) $pointCompanyId === (int) $userCompanyId;
    }

    public function rules(): array
    {
        $emisionPoint = $this->route('emisionPoint');

        return [
            'branch_id' => 'sometimes|required|exists:branches,id',
            'point' => [
                'sometimes',
                'required',
                'numeric',
                'between:1,999',
                Rule::unique('emision_points')
                    ->where(fn ($query) => $query->where('branch_id', $this->branch_id ?? $emisionPoint->branch_id))
                    ->ignore($emisionPoint->id),
            ],
            'invoice' => 'sometimes|required|numeric|min:0',
            'creditnote' => 'sometimes|required|numeric|min:0',
            'retention' => 'sometimes|required|numeric|min:0',
            'referralguide' => 'sometimes|required|numeric|min:0',
            'settlementonpurchase' => 'sometimes|required|numeric|min:0',
            'recognition' => 'nullable|string',
        ];
    }

    public function messages(): array
    {
        return [
            'point.unique' => 'Este punto de emisión ya existe en la sucursal seleccionada.',
            'point.between' => 'El punto de emisión debe estar entre 001 y 999.',
            'numeric' => 'El valor debe ser numérico.',
            'required' => 'Este campo es obligatorio cuando se envía.',
            'exists' => 'La sucursal seleccionada no es válida.',
        ];
    }
}
