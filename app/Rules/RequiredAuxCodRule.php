<?php

namespace App\Rules;

use App\Models\Product\Product;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

class RequiredAuxCodRule implements DataAwareRule, ValidationRule
{
    /**
     * Runs even when the value is empty, so a blank aux_cod can still fail.
     */
    public bool $implicit = true;

    /**
     * @var array<array-key, mixed>
     */
    protected array $data = [];

    /**
     * @param  array<array-key, mixed>  $data
     */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $rowIndex = strtok($attribute, '.');
        $row = $this->data[$rowIndex] ?? $this->data;
        $type = (int) ($row['tipo'] ?? 0);
        $iva = (int) ($row['iva'] ?? 0);

        if ($type === Product::TYPE_PRODUCT && $iva === 5 && blank($value)) {
            $fail('El código auxiliar es obligatorio para este producto.');
        }
    }
}
