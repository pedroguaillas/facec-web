<?php

namespace App\Http\Requests\Product;

use App\Models\Product\Product;
use App\Services\Product\ProductUnitService;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Validator;

/**
 * Alta/edición de una unidad (variante) de un producto modelo con plantilla.
 * `details` = `{key: valor}` de los campos de nivel `unidad`. `code` solo se
 * usa si la plantilla no tiene campo de código.
 */
class ProductUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::check();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'details' => ['present', 'array'],
            'code' => ['nullable', 'string', 'max:25'],
        ];
    }

    /**
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $service = app(ProductUnitService::class);
                $unit = $this->unit();

                if ($unit && $service->isUsed($unit)) {
                    $validator->errors()->add('product', 'La unidad ya se usó en comprobantes y no se puede modificar.');

                    return;
                }

                /** @var array<string, mixed> $details */
                $details = $this->input('details');

                foreach ($service->validationErrors($this->modelProduct(), $details, $this->input('code'), $unit) as $attribute => $message) {
                    $validator->errors()->add($attribute, $message);
                }
            },
        ];
    }

    /**
     * Código resultante (generado o enviado). Solo válido tras validar.
     */
    public function unitCode(): string
    {
        /** @var array<string, mixed> $details */
        $details = $this->input('details');

        return (string) app(ProductUnitService::class)->codeFor($this->modelProduct()->template, $details, $this->input('code'));
    }

    public function modelProduct(): Product
    {
        /** @var Product $product */
        $product = $this->route('product');

        return $product;
    }

    public function unit(): ?Product
    {
        $unit = $this->route('unit');

        return $unit instanceof Product ? $unit : null;
    }
}
