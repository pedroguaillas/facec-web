<?php

namespace App\Http\Requests\Product;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Product\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ProductUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $auth = auth()->user();
        $company = $auth->company;
        $branch = Branch::where('company_id', $company->id)
            ->orderBy('created_at')->first();
        $product = $this->route('product');

        return [
            'code' => [
                'required',
                Rule::unique('products')
                    ->ignore($product) // 👈 Ignorar el producto actual
                    ->where(fn ($query) => $query->where('branch_id', $branch->id)
                    ),
            ],
            'type_product' => 'required|integer|in:1,2',
            'name' => 'required|string|max:300',
            'price1' => 'required|numeric|min:0',
            'iva' => [
                'required',
                'integer',
                'exists:iva_taxes,code',
                Rule::when($company->isNegocioPopular(), Rule::in(Company::NEGOCIO_POPULAR_IVA_CODES)),
            ],
            'ice' => 'nullable|integer|exists:ice_cataloges,code',
            'aux_cod' => 'nullable|string|max:10',
            'stock' => 'nullable|integer|min:0',
            // Solo empresas con IVA diferenciado (`base8`) pueden marcarlo.
            'differentiated_iva' => ['sometimes', 'boolean', Rule::prohibitedIf(! $company->base8 && $this->boolean('differentiated_iva'))],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $requiresAuxCod = (int) $this->input('type_product') === Product::TYPE_PRODUCT
                    && (int) $this->input('iva') === 5;

                if ($requiresAuxCod && blank($this->input('aux_cod'))) {
                    $validator->errors()->add('aux_cod', 'El código auxiliar es obligatorio para este producto.');
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'code.required' => 'El código es obligatorio',
            'code.unique' => 'Ya existe un producto con el código :input',
            'type_product.required' => 'El tipo de producto es obligatorio',
            'type_product.in' => 'El tipo de producto no es válido',
            'name.required' => 'El nombre es obligatorio',
            'price1.required' => 'El precio es obligatorio',
            'price1.numeric' => 'El precio debe ser un número',
            'iva.required' => 'El IVA es obligatorio',
            'iva.exists' => 'El IVA seleccionado no es válido',
            'differentiated_iva.prohibited' => 'La empresa no está habilitada para IVA diferenciado (8%).',
            'iva.in' => 'Como contribuyente RIMPE Negocio Popular solo puede usar IVA 0%.',
            'ice.exists' => 'El ICE seleccionado no es válido',
        ];
    }
}
