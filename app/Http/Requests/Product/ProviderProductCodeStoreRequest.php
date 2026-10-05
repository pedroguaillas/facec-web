<?php

namespace App\Http\Requests\Product;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class ProviderProductCodeStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::check();
    }

    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('provider_product_codes', 'code')->where('provider_id', $this->route('provider')?->id),
            ],
        ];
    }
}
