<?php

namespace App\Http\Requests\Order;

use App\Models\Customer;
use App\Models\Product\Product;
use App\Models\Product\SriCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class OrderStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_id' => 'required|integer|exists:customers,id',
            'total' => 'required|numeric',
            'voucher_type' => 'required|integer|in:1,4',
            'point_id' => 'required|integer|exists:emision_points,id',
            'serie' => 'required|string',
            'date' => 'required|date',
            'send' => 'sometimes|boolean',
            'products' => 'present|array',
            'products.*.product_id' => 'required|integer|exists:products,id',
            'products.*.quantity' => 'required|numeric',
            'products.*.price' => 'required|numeric',
            'products.*.discount' => 'required|numeric',
            'products.*.iva' => 'required',
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
}
