<?php

namespace App\Services\Product;

use App\Models\Product\Product;
use App\Models\Product\ProductDetail;
use App\Models\Product\ProductTemplate;
use App\Models\Product\ProductTemplateField;
use Illuminate\Database\Eloquent\Collection;

/**
 * Valida y guarda los valores de las características de un producto
 * (`details`: `{key: valor}`), por nivel de la plantilla: el producto modelo
 * guarda los de nivel `modelo` y cada unidad los de nivel `unidad`.
 * Ver docs/caracteristicas-producto.md.
 */
class ProductDetailService
{
    /**
     * @param  array<string, mixed>  $values
     * @return array<string, string> Errores por `details.{key}`.
     */
    public function errors(ProductTemplate $template, string $level, array $values, ?int $ignoredProductId = null): array
    {
        $errors = [];

        foreach ($this->fieldsOf($template, $level) as $field) {
            $value = $this->normalize($values[$field->key] ?? null);
            $attribute = "details.{$field->key}";

            if ($value === null) {
                if ($field->required) {
                    $errors[$attribute] = "{$field->label} es obligatorio.";
                }

                continue;
            }

            $error = match (true) {
                mb_strlen($value) > 300 => "{$field->label} admite máximo 300 caracteres.",
                $field->type === ProductTemplateField::TYPE_NUMBER && ! is_numeric($value) => "{$field->label} debe ser un número.",
                $field->type === ProductTemplateField::TYPE_YEAR && ! preg_match('/^\d{4}$/', $value) => "{$field->label} debe ser un año de 4 dígitos.",
                $field->unique && $this->isTaken($field, $value, $ignoredProductId) => "Ya existe un producto con {$field->label} {$value}.",
                default => null,
            };

            if ($error) {
                $errors[$attribute] = $error;
            }
        }

        return $errors;
    }

    /**
     * Guarda los valores del nivel indicado y borra los que quedaron vacíos o
     * que pertenecen a otra plantilla (el producto cambió de plantilla).
     *
     * @param  array<string, mixed>  $values
     */
    public function sync(Product $product, ProductTemplate $template, string $level, array $values): void
    {
        $fields = $this->fieldsOf($template, $level);
        $keptFieldIds = [];

        foreach ($fields as $field) {
            $value = $this->normalize($values[$field->key] ?? null);

            if ($value === null) {
                continue;
            }

            $product->details()->updateOrCreate(['product_template_field_id' => $field->id], ['value' => $value]);
            $keptFieldIds[] = $field->id;
        }

        // Recién creado no tiene valores previos que limpiar.
        if ($product->wasRecentlyCreated) {
            return;
        }

        // Los campos inactivos conservan su valor: solo dejan de pedirse y mostrarse.
        $product->details()
            ->whereNotIn('product_template_field_id', $keptFieldIds)
            ->whereDoesntHave('field', fn ($query) => $query->where('product_template_id', $template->id)->where('active', false))
            ->delete();
    }

    /**
     * Quita todas las características (el producto dejó de tener plantilla).
     */
    public function clear(Product $product): void
    {
        $product->details()->delete();
    }

    /**
     * Valores guardados del producto como `{key: valor}`.
     *
     * @return array<string, string>
     */
    public function valuesOf(Product $product): array
    {
        // Reusa la relación si ya viene precargada (listado de unidades).
        $product->loadMissing('details.field');

        return $product->details
            ->mapWithKeys(fn (ProductDetail $detail) => [$detail->field->key => $detail->value])
            ->all();
    }

    /**
     * Usa los campos precargados de la plantilla si los hay (varias líneas de
     * una compra con el mismo modelo no los vuelven a consultar).
     *
     * @return Collection<int, ProductTemplateField>
     */
    private function fieldsOf(ProductTemplate $template, string $level)
    {
        $template->loadMissing('fields');

        return $template->fields->where('level', $level)->where('active', true)->values();
    }

    private function isTaken(ProductTemplateField $field, string $value, ?int $ignoredProductId): bool
    {
        return ProductDetail::query()
            ->where('product_template_field_id', $field->id)
            ->where('value', $value)
            // Un producto eliminado (soft delete) libera su valor.
            ->whereHas('product')
            ->when($ignoredProductId, fn ($query) => $query->where('product_id', '<>', $ignoredProductId))
            ->exists();
    }

    private function normalize(mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value === '' ? null : $value;
    }
}
