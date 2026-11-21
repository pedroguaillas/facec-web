<?php

namespace App\Http\Requests\Order;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Product\Product;
use App\Models\Product\SriCategory;
use App\Models\Treasury\PaymentReceipt;
use App\Models\VoucherType;
use App\Rules\ProductPresentationLinesRule;
use App\Services\Product\UnitSaleService;
use App\Services\SubscriptionGate;
use App\StaticClasses\MovementPartyRegistry;
use App\StaticClasses\PaymentTypes;
use App\StaticClasses\VoucherTypes;
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
        $hasFinancieroAccess = app(SubscriptionGate::class)->hasFinancieroAccess($this->user()->company);
        // Con `payments` la caja/banco va por línea, no a nivel venta.
        $sourceRequired = $this->input('payment_type') === $paymentTypeContado
            && ! $this->has('payments')
            && $hasFinancieroAccess;
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
                Rule::requiredIf($this->hasCreditPaymentLine()),
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
            // Comprobante subido antes (POST payment-receipts), solo con banco.
            'receipt_id' => PaymentReceipt::idRules($this->input('source_type')),
            'receipt_number' => PaymentReceipt::numberRules($this->input('source_type')),
            ...$this->paymentLineRules($hasFinancieroAccess),
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

                $customer = Customer::find($this->integer('customer_id'));

                if ($customer && $customer->identication === '9999999999999' && $this->total > 50) {
                    $validator->errors()->add('total', 'No es posible una venta mayor a $50 a consumidor final.');
                }

                // El SRI rechaza notas de crédito a consumidor final.
                if ($customer && $customer->identication === '9999999999999'
                    && VoucherType::whereKey($this->voucher_type_id)->value('slug') === VoucherTypes::VENTA_NOTA_CREDITO) {
                    $validator->errors()->add('customer_id', 'No se puede emitir una nota de crédito a consumidor final');
                }

                if (blank($this->plate) && $this->hasTransportService()) {
                    $validator->errors()->add('plate', 'La placa es obligatoria cuando la venta incluye un servicio de transporte.');
                }

                if ($this->has('payments')) {
                    $paymentsTotal = round(collect((array) $this->input('payments', []))->sum(fn ($line) => (float) ($line['amount'] ?? 0)), 2);

                    if (abs($paymentsTotal - round((float) $this->total, 2)) > 0.01) {
                        $validator->errors()->add('payments', "La suma de las formas de pago ({$paymentsTotal}) debe ser igual al total de la venta ({$this->total}).");
                    }
                }

                $voucherSlug = VoucherType::whereKey($this->voucher_type_id)->value('slug');
                $unitErrors = app(UnitSaleService::class)->lineErrors(
                    $this->input('products', []),
                    (string) $voucherSlug,
                    (bool) $this->user()->company->inventory,
                );

                foreach ($unitErrors as $attribute => $message) {
                    $validator->errors()->add($attribute, $message);
                }
            },
        ];
    }

    /**
     * Reglas de `payments` (varias formas de pago). Cada línea es un <pago>
     * del XML; las de contado entran a su propia caja/banco.
     *
     * @return array<string, mixed>
     */
    private function paymentLineRules(bool $hasFinancieroAccess): array
    {
        $rules = [
            'payments' => [
                'sometimes',
                'array',
                'min:1',
                Rule::prohibitedIf($this->input('payment_type') === PaymentTypes::ANTICIPO),
            ],
            'payments.*.pay_method' => 'required|integer|exists:method_of_payments,code',
            'payments.*.amount' => 'required|numeric|gt:0',
            'payments.*.payment_type' => ['required', 'string', Rule::in([PaymentTypes::CONTADO, PaymentTypes::CREDITO])],
        ];

        foreach ((array) $this->input('payments', []) as $index => $line) {
            $lineSourceType = is_array($line) ? ($line['source_type'] ?? null) : null;
            $isContado = is_array($line) && ($line['payment_type'] ?? null) === PaymentTypes::CONTADO;
            $sourceRequired = $isContado && $hasFinancieroAccess;

            $rules["payments.{$index}.source_type"] = [
                Rule::requiredIf($sourceRequired),
                Rule::prohibitedIf(! $isContado),
                'nullable',
                Rule::in([MovementPartyRegistry::BANK_ACCOUNT, MovementPartyRegistry::CASH_REGISTER_SESSION]),
            ];
            $rules["payments.{$index}.source_id"] = [
                Rule::requiredIf($sourceRequired),
                Rule::prohibitedIf(! $isContado),
                'nullable',
                'integer',
                Rule::exists($lineSourceType === MovementPartyRegistry::BANK_ACCOUNT ? 'bank_accounts' : 'cash_register_sessions', 'id'),
            ];
            $rules["payments.{$index}.receipt_id"] = PaymentReceipt::idRules($lineSourceType);
            $rules["payments.{$index}.receipt_number"] = PaymentReceipt::numberRules($lineSourceType);
        }

        return $rules;
    }

    private function hasCreditPaymentLine(): bool
    {
        return collect((array) $this->input('payments', []))
            ->contains(fn ($line) => is_array($line) && ($line['payment_type'] ?? null) === PaymentTypes::CREDITO);
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
            'payments.prohibited' => 'Una venta con anticipo no admite varias formas de pago.',
            'due_date.required' => 'La fecha de vencimiento es obligatoria cuando una forma de pago es a crédito.',
        ];
    }
}
