<?php

namespace App\Http\Requests\Branch;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BranchUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $branch = $this->route('branch');
        if ($user->isAdmin()) {
            return true;
        }

        return $branch->company_id === $user->company->id;
    }

    public function rules(): array
    {
        $branch = $this->route('branch');

        $companyId = $this->user()->isAdmin() ? $branch->company_id : $this->user()->company->id;
        $branchId = $branch->id;

        return [
            'store' => [
                'sometimes',
                'required',
                'integer',
                'between:1,999',
                Rule::unique('branches')
                    ->where(fn ($query) => $query->where('company_id', $companyId))
                    ->ignore($branchId),
            ],
            'address' => 'sometimes|required|string|min:3|max:300',
            'name' => 'sometimes|nullable|string|max:300',
            'type' => [
                'sometimes',
                'required',
                Rule::in(['matriz', 'sucursal']),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'store.required' => 'El número de establecimiento es obligatorio.',
            'store.integer' => 'El establecimiento debe ser un número entero.',
            'store.between' => 'El establecimiento debe estar entre 001 y 999.',
            'store.unique' => 'Este número de establecimiento ya existe en la empresa.',
            'address.required' => 'La dirección es obligatoria.',
            'address.min' => 'La dirección debe tener al menos 3 caracteres.',
            'type.required' => 'El tipo de establecimiento es obligatorio.',
            'type.in' => 'El tipo debe ser "matriz" o "sucursal".',
        ];
    }
}
