<?php

namespace App\Http\Requests\RecurringInvoice;

use App\Models\Company;
use App\Models\Customer;
use App\Services\RecurringInvoice\RecurringInvoiceService;
use App\Services\SubscriptionGate;
use App\StaticClasses\MovementPartyRegistry;
use App\StaticClasses\PaymentTypes;
use App\StaticClasses\RecurringFrequencies;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Plantilla de factura recurrente (crear y editar). Solo factura; la
 * forma de pago sigue la regla de ventas: crédito requiere plan
 * financiero, y contado con plan financiero pide la caja/banco del cobro.
 */
class RecurringInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $company = $this->user()->company;
        $sourceRequired = $this->input('payment_type') === PaymentTypes::CONTADO
            && app(SubscriptionGate::class)->hasFinancieroAccess($company);
        $sourceTable = $this->input('source_type') === MovementPartyRegistry::BANK_ACCOUNT ? 'bank_accounts' : 'cash_registers';

        return [
            'name' => ['required', 'string', 'max:100'],
            'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')],
            'emision_point_id' => ['required', 'integer', Rule::exists('emision_points', 'id')],
            'frequency' => ['required', Rule::in(RecurringFrequencies::ALL)],
            // Fecha y hora de la primera emisión; las siguientes salen a la misma hora.
            'start_at' => ['required', 'date_format:Y-m-d H:i'],
            'end_date' => ['nullable', 'date_format:Y-m-d'],
            'max_occurrences' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'auto_send' => ['sometimes', 'boolean'],
            'payment_type' => ['required', Rule::in([PaymentTypes::CONTADO, PaymentTypes::CREDITO])],
            'due_days' => ['required_if:payment_type,'.PaymentTypes::CREDITO, 'nullable', 'integer', 'min:0', 'max:365'],
            'pay_method' => ['required', 'integer', Rule::exists('method_of_payments', 'code')],
            'source_type' => [Rule::requiredIf($sourceRequired), 'nullable', Rule::in([MovementPartyRegistry::BANK_ACCOUNT, MovementPartyRegistry::CASH_REGISTER])],
            'source_id' => [Rule::requiredIf($sourceRequired), 'nullable', 'integer', Rule::exists($sourceTable, 'id')],
            'description' => ['nullable', 'string', 'max:300'],
            'aditionals' => ['sometimes', 'array', 'max:10'],
            'aditionals.*.name' => ['required', 'string', 'max:50'],
            'aditionals.*.description' => ['required', 'string', 'max:300'],
            'products' => ['required', 'array', 'min:1'],
            'products.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')],
            // Con marcadores ({mes}, {mm}, {año}, {periodo}, {fecha}); vacío = los del producto.
            'products.*.code' => ['nullable', 'string', 'max:40'],
            'products.*.description' => ['nullable', 'string', 'max:300'],
            'products.*.quantity' => ['required', 'numeric', 'gt:0'],
            'products.*.price' => ['required', 'numeric', 'min:0'],
            'products.*.discount' => ['sometimes', 'numeric', 'min:0'],
            'products.*.iva' => [
                'required',
                'integer',
                Rule::when($company?->isNegocioPopular() ?? false, Rule::in(Company::NEGOCIO_POPULAR_IVA_CODES)),
            ],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $customer = Customer::find($this->integer('customer_id'));

                if ($customer && $customer->identication === '9999999999999') {
                    $validator->errors()->add('customer_id', 'Una factura recurrente no puede ser a consumidor final.');
                }

                $this->validateRenderedItemTexts($validator);

                if ($this->filled('end_date') && $this->input('end_date') < substr((string) $this->input('start_at'), 0, 10)) {
                    $validator->errors()->add('end_date', 'La fecha fin no puede ser anterior a la fecha de inicio.');
                }

                if ($this->input('payment_type') === PaymentTypes::CREDITO
                    && ! app(SubscriptionGate::class)->hasFinancieroAccess($this->user()->company)) {
                    $validator->errors()->add('payment_type', 'La venta a crédito requiere el plan financiero.');
                }
            },
        ];
    }

    /**
     * Código/descripción renderizados en el peor caso (septiembre, el mes
     * más largo) deben caber en los límites del SRI.
     */
    private function validateRenderedItemTexts(Validator $validator): void
    {
        $service = app(RecurringInvoiceService::class);
        $longestMonth = CarbonImmutable::create(2026, 9, 30);
        $limits = [
            'code' => RecurringInvoiceService::ITEM_CODE_MAX,
            'description' => RecurringInvoiceService::ITEM_DESCRIPTION_MAX,
        ];

        foreach ((array) $this->input('products', []) as $index => $product) {
            foreach ($limits as $field => $max) {
                $template = $product[$field] ?? null;

                if (filled($template) && mb_strlen($service->replacePlaceholders((string) $template, $longestMonth)) > $max) {
                    $validator->errors()->add("products.{$index}.{$field}", "Con el mes reemplazado supera los {$max} caracteres que acepta el SRI.");
                }
            }
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'products.*.iva.in' => 'Como contribuyente RIMPE Negocio Popular solo puede usar IVA 0%.',
            'products.required' => 'Agrega al menos un producto.',
        ];
    }
}
