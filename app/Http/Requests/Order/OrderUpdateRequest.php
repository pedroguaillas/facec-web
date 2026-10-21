<?php

namespace App\Http\Requests\Order;

use App\Models\Company;
use App\Models\Order\Order;
use App\Rules\ProductPresentationLinesRule;
use App\StaticClasses\VoucherStates;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class OrderUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'total' => 'required|numeric',
            'products' => ['present', 'array', new ProductPresentationLinesRule($this->usedPresentationIds())],
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
            'plate' => 'sometimes|nullable|string|max:10',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $order = $this->route('order');

                $protectedStates = [
                    VoucherStates::SENDED,
                    VoucherStates::RECEIVED,
                    VoucherStates::IN_PROCESS,
                    VoucherStates::AUTHORIZED,
                    VoucherStates::CANCELED,
                ];

                if (in_array($order->state, $protectedStates)) {
                    $validator->errors()->add('order', 'No se puede modificar una orden en estado '.$order->state.'.');
                }
            },
        ];
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

    /**
     * Presentaciones que ya tienen las líneas guardadas: se aceptan aunque
     * después se hayan desactivado.
     *
     * @return array<int, int>
     */
    private function usedPresentationIds(): array
    {
        $order = $this->route('order');

        if (! $order instanceof Order) {
            return [];
        }

        return $order->orderitems()->whereNotNull('presentation_id')->pluck('presentation_id')->map(fn ($id) => (int) $id)->all();
    }
}
