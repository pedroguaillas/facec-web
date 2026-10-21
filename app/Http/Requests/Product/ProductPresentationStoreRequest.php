<?php

namespace App\Http\Requests\Product;

use App\Models\Product\Product;
use App\Models\Product\ProductPresentation;
use App\Rules\AvailablePresentationCodeRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class ProductPresentationStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::check();
    }

    public function rules(): array
    {
        $product = $this->route('product');
        $presentation = $this->route('presentation');

        return [
            'name' => ['required', 'string', 'max:255'],
            'conversion_factor' => ['required', 'numeric', 'min:0.000001', 'decimal:0,6'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'code' => [
                'nullable',
                'string',
                'max:25',
                ...($product instanceof Product
                    ? [new AvailablePresentationCodeRule($product->branch_id, $presentation instanceof ProductPresentation ? $presentation->id : null)]
                    : []),
            ],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
