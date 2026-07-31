<?php

namespace App\Http\Requests\EmisionPoint;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EmisionPointStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'branch_id' => 'required|exists:branches,id',
            'point' => [
                'required',
                'numeric',
                'digits:3',
                Rule::unique('emision_points')->where(function ($query) {
                    return $query->where('branch_id', $this->branch_id);
                }),
            ],
            'invoice' => 'required|numeric',
            'creditnote' => 'required|numeric',
            'retention' => 'required|numeric',
            'referralguide' => 'required|numeric',
            'settlementonpurchase' => 'required|numeric',
            'recognition' => 'nullable|string',
        ];
    }

    public function messages(): array
    {
        return [
            'branch_id.required' => 'La sucursal es obligatoria.',
            'branch_id.exists' => 'La sucursal seleccionada no existe.',
            'point.unique' => 'Este punto de emisión (point) ya está registrado para la sucursal seleccionada.',
            'point.digits' => 'El punto de emisión debe tener 3 dígitos (ej: 001).',
            'required' => 'Este campo es obligatorio.',
            'numeric' => 'Debe ser un valor numérico.',
            'invoice.required' => ' El secuencial de facturas es obligatorio.',
            'creditnote.required' => 'El secuencial de notas de crédito es obligatorio.',
            'retention.required' => 'El secuencial de retenciones es obligatorio.',
            'referralguide.required' => 'El secuencial de guías de remisión es obligatorio.',
            'settlementonpurchase.required' => 'El secuencial de liquidaciones de compra es obligatorio.',
        ];
    }
}
