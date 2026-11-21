<?php

namespace App\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

/**
 * Registro manual de la retención que el cliente emitió sobre una factura.
 */
class OrderRetentionStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::check();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'serie' => ['required', 'string', 'regex:/^\d{3}-\d{3}-\d{9}$/'],
            'date' => ['required', 'date_format:Y-m-d'],
            'authorization' => ['required', 'string', 'regex:/^\d{10,49}$/'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.code' => ['required', 'integer', 'in:1,2'],
            'items.*.tax_code' => ['required', 'string', 'max:10'],
            'items.*.base' => ['required', 'numeric', 'min:0'],
            'items.*.porcentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'items.*.value' => ['required', 'numeric', 'gt:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'serie.regex' => 'La serie debe tener el formato 001-001-000000001.',
            'authorization.regex' => 'El número de autorización debe tener solo dígitos (10 a 49).',
            'items.*.code.in' => 'El impuesto retenido debe ser renta (1) o IVA (2).',
        ];
    }
}
