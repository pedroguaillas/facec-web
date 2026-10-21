<?php

namespace App\Http\Requests\Order;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Product\Product;
use App\Models\Product\SriCategory;
use App\Rules\ProductPresentationLinesRule;
use App\Services\SubscriptionGate;
use App\StaticClasses\MovementPartyRegistry;
use App\StaticClasses\PaymentTypes;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class OrderStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $sourceTable = $this->input('source_type') === MovementPartyRegistry::BANK_ACCOUNT
            ? 'bank_accounts'
            : 'cash_register_sessions';

        $paymentTypeContado = PaymentTypes::CONTADO;
        // Caja/banco solo con plan financiero: con FACTURADOR la venta/compra al
        // contado no toca tesorería (lo decide el plan, no el front).
        $sourceRequired = $this->input('payment_type') === $paymentTypeContado
            && app(SubscriptionGate::class)->hasFinancieroAccess($this->user()->company);
        $paymentTypeCredito = PaymentTypes::CREDITO;
        $paymentTypeAnticipo = PaymentTypes::ANTICIPO;

        return [
            'customer_id' => 'required|integer|exists:customers,id',
            'total' => 'required|numeric',
            'voucher_type_id' => [
                'required',
                'integer',
                Rule::exists('voucher_types', 'id')->where('module', 'venta')->where('active', true),
            ],
            'point_id' => 'required|integer|exists:emision_points,id',
            'date' => 'required|date',
            'send' => 'sometimes|boolean',
            'products' => ['present', 'array', new ProductPresentationLinesRule],
            'products.*.product_id' => 'required|integer|exists:products,id',
            'products.*.presentation_id' => 'nullable|integer',
            'products.*.quantity' => 'required|numeric',
            'products.*.price' => 'required|numeric',
            'products.*.discount' => 'required|numeric',
            'products.*.iva' => [
                'required',
                Rule::when($this->user()->company?->isNegocioPopular() ?? false, Rule::in(Company::NEGOCIO_POPULAR_IVA_CODES)),
            ],
            'aditionals' => 'present|array',
            'repayments' => 'sometimes|array',
            'sub_total' => 'sometimes|numeric',
            'no_iva' => 'sometimes|numeric',
            'base0' => 'sometimes|numeric',
            'base5' => 'sometimes|numeric',
            'base8' => 'sometimes|numeric',
            'base12' => 'sometimes|numeric',
            'base15' => 'sometimes|numeric',
            'iva' => 'sometimes|numeric',
            'iva5' => 'sometimes|numeric',
            'iva8' => 'sometimes|numeric',
            'iva15' => 'sometimes|numeric',
            'ice' => 'sometimes|numeric',
            'discount' => 'sometimes|numeric',
            'pay_method' => 'sometimes|integer',
            'description' => 'sometimes|nullable|string',
            'guia' => 'sometimes|nullable|string',
            'expiration_days' => 'sometimes|integer',
            'payment_type' => ['sometimes', 'nullable', 'string', Rule::in([
                PaymentTypes::CONTADO,
                PaymentTypes::CREDITO,
                PaymentTypes::ANTICIPO,
            ])],
            // Fecha de vencimiento. Obligatoria en CREDITO; también en ANTICIPO por si
            // el anticipo disponible no alcanza a cubrir todo y el resto queda a crédito.
            // expiration_days (días de plazo) se deriva de esta, no se pide por separado.
            'due_date' => [
                "required_if:payment_type,{$paymentTypeCredito},{$paymentTypeAnticipo}",
                'nullable',
                'date',
                'after_or_equal:date',
            ],
            'movement_id' => 'sometimes|nullable|integer|exists:movements,id',
            // Solo aplica si payment_type=CONTADO: a qué banco/caja entra el cobro.
            'source_type' => [
                Rule::requiredIf($sourceRequired),
                'nullable',
                Rule::in([MovementPartyRegistry::BANK_ACCOUNT, MovementPartyRegistry::CASH_REGISTER_SESSION]),
            ],
            'source_id' => [
                Rule::requiredIf($sourceRequired),
                'nullable',
                'integer',
                Rule::exists($sourceTable, 'id'),
            ],
            'date_order' => 'sometimes|nullable|date',
            'serie_order' => 'sometimes|nullable|string',
            'reason' => 'sometimes|nullable|string',
            'plate' => 'sometimes|nullable|string|max:10',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $customer = Customer::find($this->customer_id);

                if ($customer && $customer->identication === '9999999999999' && $this->total > 50) {
                    $validator->errors()->add('total', 'No es posible una venta mayor a $50 a consumidor final.');
                }

                if (blank($this->plate) && $this->hasTransportService()) {
                    $validator->errors()->add('plate', 'La placa es obligatoria cuando la venta incluye un servicio de transporte.');
                }
            },
        ];
    }

    private function hasTransportService(): bool
    {
        $productIds = collect($this->products)->pluck('product_id');

        $transportAuxCodes = SriCategory::where('type', 'transporte')->pluck('code');

        return Product::whereIn('id', $productIds)
            ->where('type_product', Product::TYPE_SERVICE)
            ->whereIn('aux_cod', $transportAuxCodes)
            ->exists();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'products.*.iva.in' => 'Como contribuyente RIMPE Negocio Popular solo puede usar IVA 0%.',
        ];
    }
}
