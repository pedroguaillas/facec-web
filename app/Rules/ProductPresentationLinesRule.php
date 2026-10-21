<?php

namespace App\Rules;

use App\Models\Product\ProductPresentation;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\ValidatorAwareRule;
use Illuminate\Validation\Validator;

/**
 * Se aplica sobre el arreglo `products` completo: cada
 * `products.N.presentation_id` (opcional) debe ser una presentación activa de
 * `products.N.product_id`. Una inactiva solo se acepta si ya estaba en el
 * comprobante que se edita (`$alreadyUsedIds`). Una sola consulta; errores en
 * `products.N.presentation_id`.
 */
class ProductPresentationLinesRule implements ValidationRule, ValidatorAwareRule
{
    private Validator $validator;

    /**
     * @param  array<int, int>  $alreadyUsedIds  Presentaciones que ya tienen las líneas del comprobante editado.
     */
    public function __construct(private array $alreadyUsedIds = []) {}

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

        $presentationIds = collect($value)->pluck('presentation_id')->filter(fn ($id) => is_numeric($id))->unique();

        if ($presentationIds->isEmpty()) {
            return;
        }

        $presentations = ProductPresentation::whereIn('id', $presentationIds)->get(['id', 'product_id', 'active'])->keyBy('id');

        foreach ($value as $index => $line) {
            if (! is_numeric($line['presentation_id'] ?? null)) {
                continue;
            }

            $presentation = $presentations->get((int) $line['presentation_id']);

            if ($presentation === null || (int) $presentation->product_id !== (int) ($line['product_id'] ?? 0)) {
                $this->validator->errors()->add("{$attribute}.{$index}.presentation_id", 'La presentación no corresponde al producto.');

                continue;
            }

            if (! $presentation->active && ! in_array($presentation->id, $this->alreadyUsedIds, true)) {
                $this->validator->errors()->add("{$attribute}.{$index}.presentation_id", 'La presentación está inactiva.');
            }
        }
    }
}
