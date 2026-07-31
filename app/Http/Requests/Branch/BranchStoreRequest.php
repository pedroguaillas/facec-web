<?php

namespace App\Http\Requests\Branch;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BranchStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->user()->isAdmin()) {
            $this->merge([
                'company_id' => $this->user()->company?->id,
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'company_id' => 'required|exists:companies,id',
            'store' => [
                'required',
                'integer',
                'between:1,999',
                Rule::unique('branches')->where(
                    fn ($query) => $query->where('company_id', $this->company_id)
                ),
            ],
            'address' => 'required|string|min:3|max:300',
            'name' => 'nullable|string|max:300',
            'type' => ['required', Rule::in(['matriz', 'sucursal'])],
            'cf' => 'sometimes|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'company_id.required' => 'La empresa es obligatoria.',
            'store.unique' => 'Este establecimiento ya existe en la empresa.',
            'store.between' => 'El número debe estar entre 1 y 999.',
        ];
    }
}
