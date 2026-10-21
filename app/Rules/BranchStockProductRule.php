<?php

namespace App\Rules;

use App\Models\Product\Product;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\ValidatorAwareRule;
use Illuminate\Validation\Validator;

/**
 * Se aplica sobre el arreglo `items` completo (no por ítem) para validar con
 * una sola consulta que cada `items.N.product_id` pertenece a la sucursal del
 * usuario (vía BranchScope) y es inventariable (los servicios no tienen
 * stock). Los errores se reportan en `items.N.product_id`.
 */
class BranchStockProductRule implements ValidationRule, ValidatorAwareRule
{
    private Validator $validator;

    public function setValidator(Validator $validator): static
    {
        $this->validator = $validator;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            return;
        }

        $productIds = collect($value)->pluck('product_id')->filter(fn ($id) => is_numeric($id))->unique();

        $types = Product::whereIn('id', $productIds)->pluck('type_product', 'id');

        foreach ($value as $index => $item) {
            if (! is_numeric($item['product_id'] ?? null)) {
                continue;
            }

            $productId = (int) $item['product_id'];

            if (! $types->has($productId)) {
                $this->validator->errors()->add("{$attribute}.{$index}.product_id", 'El producto seleccionado no existe.');
            } elseif ((int) $types[$productId] !== Product::TYPE_PRODUCT) {
                $this->validator->errors()->add("{$attribute}.{$index}.product_id", 'Los servicios no manejan inventario.');
            }
        }
    }
}
