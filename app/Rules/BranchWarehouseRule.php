<?php

namespace App\Rules;

use App\Models\Product\Warehouse;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * La bodega existe y pertenece a la sucursal del usuario (vía BranchScope).
 */
class BranchWarehouseRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! Warehouse::whereKey($value)->exists()) {
            $fail('La bodega seleccionada no existe.');
        }
    }
}
