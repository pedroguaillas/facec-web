<?php

namespace App\Http\Requests\Shop;

use App\StaticClasses\VoucherStates;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Validator;

class ShopUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::check();
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $shop = $this->route('shop');

                $protectedStates = [
                    VoucherStates::SENDED,
                    VoucherStates::RECEIVED,
                    VoucherStates::IN_PROCESS,
                    VoucherStates::AUTHORIZED,
                    VoucherStates::CANCELED,
                ];

                if (in_array($shop->state, $protectedStates)) {
                    $validator->errors()->add('shop', 'No se puede modificar una compra en estado '.$shop->state.'.');
                }
            },
        ];
    }

    public function rules(): array
    {
        return [
            // Cabecera
            'date' => ['sometimes', 'date'],
            'voucher_type' => ['sometimes', 'integer', 'in:1,2,3,5'],
            'provider_id' => ['sometimes', 'integer', 'exists:providers,id'],
            'serie' => ['sometimes', 'string', 'max:17'],
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
            'total' => ['sometimes', 'numeric', 'min:0'],

            // Retención
            'app_retention' => ['nullable', 'boolean'],
            'send' => ['nullable', 'boolean'],
            'serie_retencion' => ['required_if:app_retention,true', 'nullable', 'string', 'max:17'],
            'date_retention' => ['nullable', 'date'],

            // Items de retención
            'taxes' => ['required_if:app_retention,true', 'nullable', 'array'],
            'taxes.*.code' => ['required', 'string'],
            'taxes.*.tax_code' => ['required', 'string'],
            'taxes.*.base' => ['required', 'numeric', 'min:0'],
            'taxes.*.porcentage' => ['required', 'numeric'],
            'taxes.*.value' => ['required', 'numeric', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'voucher_type.in' => 'El tipo de comprobante no es válido.',
            'provider_id.exists' => 'El proveedor seleccionado no existe.',
            'serie_retencion.required_if' => 'La serie de retención es obligatoria cuando se aplica retención.',
            'taxes.required_if' => 'Los impuestos son obligatorios cuando se aplica retención.',
        ];
    }
}
