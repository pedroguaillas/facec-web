<?php

namespace App\Http\Requests\Product;

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
        return [
            'name' => ['required', 'string', 'max:255'],
            'conversion_factor' => ['required', 'numeric', 'min:0.0001'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'code' => ['nullable', 'string', 'max:25'],
        ];
    }
}
