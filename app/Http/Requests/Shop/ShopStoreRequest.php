<?php

namespace App\Http\Requests\Shop;

use App\Http\Requests\Shop\Concerns\ValidatesProviderIdentification;
use App\Models\Branch;
use App\Models\Shop\Shop;
use App\Models\VoucherType;
use App\StaticClasses\VoucherTypes;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class ShopStoreRequest extends FormRequest
{
    use ValidatesProviderIdentification;

    public function authorize(): bool
    {
        return Auth::check();
    }

    public function rules(): array
    {
        return [
            // Cabecera
            'date' => ['required', 'date'],
            'voucher_type_id' => [
                'required',
                'integer',
                Rule::exists('voucher_types', 'id')->where('module', 'compra')->where('active', true),
            ],
            'provider_id' => ['required', 'integer', 'exists:providers,id'],
            'point_id' => ['required', 'integer', 'exists:emision_points,id'],
            // La serie de liquidación en compra la genera el backend
            // (VoucherSequenceService); para el resto de tipos es el número
            // real del documento del proveedor, ahí sí es obligatoria.
            'serie' => [
                "required_unless:voucher_type_id,{$this->liquidacionVoucherTypeId()}",
                'nullable', 'string', 'max:17',
            ],
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
            $this->validateProviderIdentification($validator, $this->input('voucher_type_id'), $this->input('provider_id'));

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

    private function liquidacionVoucherTypeId(): ?int
    {
        return VoucherType::where('slug', VoucherTypes::COMPRA_LIQUIDACION_COMPRA)->value('id');
    }

    public function messages(): array
    {
        return [
            'voucher_type_id.exists' => 'El tipo de comprobante no es válido.',
            'provider_id.exists' => 'El proveedor seleccionado no existe.',
            'point_id.exists' => 'El punto de emisión seleccionado no existe.',
            'serie.required_unless' => 'La serie es obligatoria para este tipo de comprobante.',
            'taxes.required_if' => 'Los impuestos son obligatorios cuando se aplica retención.',
        ];
    }
}
