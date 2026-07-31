<?php

namespace App\Http\Requests\Shop;

use App\Models\Branch;
use App\Models\Shop\Shop;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class ShopStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::check();
    }

    public function rules(): array
    {
        return [
            // Cabecera
            'date' => ['required', 'date'],
            'voucher_type' => ['required', 'integer', 'in:1,2,3,5'],
            'provider_id' => ['required', 'integer', 'exists:providers,id'],
            'point_id' => ['required', 'integer', 'exists:emision_points,id'],
            'serie' => ['required', 'string', 'max:17'],
            'authorization' => ['nullable', 'string', 'max:49'],
            'description' => ['nullable', 'string'],
            'expiration_days' => ['nullable', 'integer', 'min:0'],
            'paid' => ['nullable', 'boolean'],
            'doc_realeted' => ['nullable', 'string'],

            // Bases imponibles
            'sub_total' => ['nullable', 'numeric', 'min:0'],
            'no_iva' => ['nullable', 'numeric', 'min:0'],
            'base0' => ['nullable', 'numeric', 'min:0'],
            'base5' => ['nullable', 'numeric', 'min:0'],
            'base12' => ['nullable', 'numeric', 'min:0'],
            'base15' => ['nullable', 'numeric', 'min:0'],
            'iva5' => ['nullable', 'numeric', 'min:0'],
            'iva' => ['nullable', 'numeric', 'min:0'],
            'iva15' => ['nullable', 'numeric', 'min:0'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'ice' => ['nullable', 'numeric', 'min:0'],
            'total' => ['required', 'numeric', 'min:0'],

            // Retención
            'app_retention' => ['nullable', 'boolean'],
            'send' => ['nullable', 'boolean'],
            'serie_retencion' => ['required_if:app_retention,true', 'nullable', 'string', 'max:17'],
            'date_retention' => ['nullable', 'date'],

            // Items de productos
            'products' => ['nullable', 'array'],
            'products.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'products.*.quantity' => ['required', 'numeric', 'min:0'],
            'products.*.price' => ['required', 'numeric', 'min:0'],
            'products.*.discount' => ['required', 'numeric', 'min:0'],
            'products.*.iva' => ['required', 'numeric', 'min:0'],

            // Items de retención
            'taxes' => ['required_if:app_retention,true', 'nullable', 'array'],
            'taxes.*.code' => ['required', 'string'],
            'taxes.*.tax_code' => ['required', 'string'],
            'taxes.*.base' => ['required', 'numeric', 'min:0'],
            'taxes.*.porcentage' => ['required', 'numeric'],
            'taxes.*.value' => ['required', 'numeric', 'min:0'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->boolean('app_retention') && ! empty($this->serie) && ! empty($this->provider_id)) {
                $auth = Auth::user();
                $branch = Branch::where('company_id', $auth->company->id)
                    ->orderBy('created_at')
                    ->first();

                if ($branch) {
                    $exists = Shop::where([
                        ['branch_id', $branch->id],
                        ['serie', $this->serie],
                        ['state_retencion', 'AUTORIZADO'],
                        ['authorization', $this->authorization],
                        ['provider_id', $this->provider_id],
                    ])->exists();

                    if ($exists) {
                        $validator->errors()->add(
                            'authorization',
                            'Ya existe una retención AUTORIZADA para esta factura'
                        );
                    }
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'voucher_type.in' => 'El tipo de comprobante no es válido.',
            'provider_id.exists' => 'El proveedor seleccionado no existe.',
            'point_id.exists' => 'El punto de emisión seleccionado no existe.',
            'serie_retencion.required_if' => 'La serie de retención es obligatoria cuando se aplica retención.',
            'taxes.required_if' => 'Los impuestos son obligatorios cuando se aplica retención.',
        ];
    }
}
