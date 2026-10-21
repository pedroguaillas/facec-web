<?php

namespace App\Http\Requests\Product;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Product\Product;
use App\Rules\AvailablePresentationCodeRule;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ProductStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Cambiar a true para permitir la autorización
    }

    public function rules(): array
    {
        $auth = auth()->user();
        $company = $auth->company;
        $branch = Branch::where('company_id', $company->id)
            ->orderBy('created_at')->first();

        return [
            'code' => [
                'required',
                Rule::unique('products')->where(fn ($query) => $query->where('branch_id', $branch->id)
                ),
                new AvailablePresentationCodeRule($branch->id, includeProducts: false),
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
            'stock' => 'nullable|numeric|min:0',
            'min_stock' => 'nullable|numeric|min:0',
            // Solo empresas con IVA diferenciado (`base8`) pueden marcarlo.
            'differentiated_iva' => ['sometimes', 'boolean', Rule::prohibitedIf(! $company->base8 && $this->boolean('differentiated_iva'))],
            // Presentaciones creadas junto con el producto (mismas reglas que
            // ProductPresentationStoreRequest). Un servicio no tiene presentaciones.
            'presentations' => ['sometimes', 'array', Rule::prohibitedIf((int) $this->input('type_product') === Product::TYPE_SERVICE)],
            'presentations.*.name' => ['required', 'string', 'max:255'],
            'presentations.*.conversion_factor' => ['required', 'numeric', 'min:0.000001', 'decimal:0,6'],
            'presentations.*.price' => ['nullable', 'numeric', 'min:0'],
            'presentations.*.code' => [
                'nullable',
                'string',
                'max:25',
                'distinct:ignore_case',
                function (string $attribute, mixed $value, Closure $fail) {
                    if (mb_strtolower((string) $value) === mb_strtolower((string) $this->input('code'))) {
                        $fail('El código ya existe en esta sucursal.');
                    }
                },
                new AvailablePresentationCodeRule($branch->id),
            ],
            'presentations.*.active' => ['sometimes', 'boolean'],
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
            'presentations.prohibited' => 'Un servicio no puede tener presentaciones.',
            'presentations.*.name.required' => 'El nombre de la presentación es obligatorio',
            'presentations.*.conversion_factor.required' => 'El factor de conversión es obligatorio',
            'presentations.*.code.distinct' => 'El código está repetido en otra presentación.',
        ];
    }
}
