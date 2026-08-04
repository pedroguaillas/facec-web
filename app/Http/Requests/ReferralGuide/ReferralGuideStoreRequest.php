<?php

namespace App\Http\Requests\ReferralGuide;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class ReferralGuideStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::check();
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'carrier_id' => ['required', 'integer', 'exists:carriers,id'],
            'point_id' => ['required', 'integer', 'exists:emision_points,id'],
            'serie' => ['required', 'string', 'max:17'],
            'address_from' => ['required', 'string', 'max:300'],
            'address_to' => ['required', 'string', 'max:300'],
            'date_start' => ['required', 'date'],
            'date_end' => ['required', 'date', 'after_or_equal:date_start'],
            'reason_transfer' => ['required', 'string', 'max:300'],
            'customs_doc' => ['nullable', 'string', 'max:20'],
            'branch_destiny' => ['nullable', 'string', 'max:3'],
            'route' => ['nullable', 'string', 'max:300'],
            'serie_invoice' => ['nullable', 'string', 'max:17'],
            'authorization_invoice' => ['nullable', 'string', 'max:49'],
            'date_invoice' => ['nullable', 'date'],
            'send' => ['nullable', 'boolean'],

            'products' => ['nullable', 'array'],
            'products.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'products.*.quantity' => ['required', 'numeric', 'min:0'],
        ];
    }
}
