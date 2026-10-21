<?php

namespace App\Rules;

use App\Models\Product\Product;
use App\Models\Product\ProductPresentation;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * El código de una presentación no puede repetirse en la sucursal, ni contra
 * otra presentación ni contra el código de un producto: `lookup` busca por
 * ambos y un código de barras debe resolver a una sola línea. Con
 * `$includeProducts = false` valida el código de un producto (la unicidad
 * entre productos ya la cubre su propia regla) solo contra presentaciones.
 * La comparación sigue la collation de MySQL: no distingue mayúsculas.
 */
class AvailablePresentationCodeRule implements ValidationRule
{
    public function __construct(
        private int $branchId,
        private ?int $ignoredPresentationId = null,
        private bool $includeProducts = true,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (blank($value)) {
            return;
        }

        $isUsedByPresentation = ProductPresentation::query()
            ->where('code', $value)
            ->whereHas('product', fn ($query) => $query->where('branch_id', $this->branchId))
            ->when($this->ignoredPresentationId, fn ($query) => $query->whereKeyNot($this->ignoredPresentationId))
            ->exists();

        $isUsedByProduct = $this->includeProducts && Product::query()
            ->where('branch_id', $this->branchId)
            ->where('code', $value)
            ->exists();

        if ($isUsedByPresentation || $isUsedByProduct) {
            $fail('El código ya existe en esta sucursal.');
        }
    }
}
