<?php

namespace App\Http\Requests\Shop\Concerns;

use App\Models\Product\Product;
use App\Models\Product\ProductTemplate;
use App\Models\Product\ProductTemplateField;
use App\Models\Shop\Shop;
use App\Models\VoucherType;
use App\Services\Product\ProductUnitService;
use App\StaticClasses\InventoryMovementTypes;
use Illuminate\Support\Collection;
use Illuminate\Validation\Validator;

/**
 * Líneas de compra con unidades (motos). Una línea puede:
 *  - traer `unit` ({key: valor} de nivel `unidad`, y `unit_code` si la
 *    plantilla no genera el código) sobre el producto modelo: registra una
 *    unidad nueva;
 *  - apuntar a una unidad existente (`product_id` de la variante).
 * En ambos casos la cantidad es 1. Un modelo cuya plantilla tiene campos de
 * nivel `unidad` no se compra sin `unit`. Ver docs/caracteristicas-producto.md.
 */
trait ValidatesUnitLines
{
    private function validateUnitLines(Validator $validator, mixed $voucherTypeId, ?Shop $shop = null): void
    {
        $lines = $this->input('products');

        if (! is_array($lines)) {
            return;
        }

        $products = Product::with(['template.codeField', 'template.fields'])
            ->whereIn('id', collect($lines)->pluck('product_id')->filter(fn ($id) => is_numeric($id)))
            ->get()
            ->keyBy('id');

        $previousUnitIds = $shop ? $this->unitIdsOf($shop) : collect();
        $service = app(ProductUnitService::class);
        $registersStock = $this->registersStock($voucherTypeId);
        /** @var array<string, int> $seen Valores únicos/códigos ya usados en otras líneas del mismo documento. */
        $seen = [];

        foreach ($lines as $index => $line) {
            $product = is_array($line) ? $products->get((int) ($line['product_id'] ?? 0)) : null;

            if (! $product) {
                continue;
            }

            $prefix = "products.{$index}";

            if (is_array($line['unit'] ?? null)) {
                $this->validateNewUnitLine($validator, $service, $product, $line, $prefix, $registersStock, $seen);

                continue;
            }

            if ($product->parent_product_id) {
                $this->validateExistingUnitLine($validator, $product, $line, $prefix, $previousUnitIds);

                continue;
            }

            if ($this->requiresUnit($product)) {
                $validator->errors()->add("{$prefix}.unit", 'Ingresa los datos de la unidad ('.$this->unitLabels($product->template).').');
            }
        }

        if ($shop) {
            $this->validateRemovedUnits($validator, $shop, $previousUnitIds, collect($lines)->pluck('product_id')->map(fn ($id) => (int) $id));
        }
    }

    /**
     * @param  array<string, mixed>  $line
     * @param  array<string, int>  $seen
     */
    private function validateNewUnitLine(Validator $validator, ProductUnitService $service, Product $model, array $line, string $prefix, bool $registersStock, array &$seen): void
    {
        if (! $registersStock) {
            $validator->errors()->add("{$prefix}.unit", 'Este tipo de comprobante no registra unidades.');

            return;
        }

        $this->validateUnitQuantity($validator, $line, $prefix);

        $errors = $service->validationErrors($model, $line['unit'], $line['unit_code'] ?? null);

        foreach ($errors as $attribute => $message) {
            $validator->errors()->add($this->lineAttribute($prefix, $attribute), $message);
        }

        if ($errors !== []) {
            return;
        }

        $template = $model->template;
        $code = (string) $service->codeFor($template, $line['unit'], $line['unit_code'] ?? null);
        $keys = ["code:{$code}" => $service->codeAttribute($template)];

        foreach ($template->fields->where('level', ProductTemplateField::LEVEL_UNIT)->where('unique', true)->where('active', true) as $field) {
            $value = trim((string) ($line['unit'][$field->key] ?? ''));

            if ($value !== '') {
                $keys["{$field->id}:".mb_strtolower($value)] = "details.{$field->key}";
            }
        }

        foreach ($keys as $key => $attribute) {
            if (isset($seen[$key])) {
                $validator->errors()->add($this->lineAttribute($prefix, $attribute), 'Está repetido en otra línea de la compra.');
            }

            $seen[$key] = 1;
        }
    }

    /**
     * Una unidad existente solo se vuelve a comprar si no está en stock (ej.
     * se vendió y se recompra), salvo que ya estuviera en esta compra.
     *
     * @param  array<string, mixed>  $line
     * @param  Collection<int, int>  $previousUnitIds
     */
    private function validateExistingUnitLine(Validator $validator, Product $unit, array $line, string $prefix, Collection $previousUnitIds): void
    {
        $this->validateUnitQuantity($validator, $line, $prefix);

        if (! $previousUnitIds->contains($unit->id) && (float) $unit->stock > 0) {
            $validator->errors()->add("{$prefix}.product_id", "La unidad {$unit->code} ya está en stock.");
        }
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function validateUnitQuantity(Validator $validator, array $line, string $prefix): void
    {
        if ((float) ($line['quantity'] ?? 0) !== 1.0) {
            $validator->errors()->add("{$prefix}.quantity", 'Cada unidad se registra en su propia línea con cantidad 1.');
        }

        if (filled($line['presentation_id'] ?? null)) {
            $validator->errors()->add("{$prefix}.presentation_id", 'Una unidad no se compra por presentación.');
        }
    }

    /**
     * Al editar, una unidad de esta compra que ya se vendió no se puede quitar.
     *
     * @param  Collection<int, int>  $previousUnitIds
     * @param  Collection<int, int>  $incomingProductIds
     */
    private function validateRemovedUnits(Validator $validator, Shop $shop, Collection $previousUnitIds, Collection $incomingProductIds): void
    {
        $removedIds = $previousUnitIds->diff($incomingProductIds);

        if ($removedIds->isEmpty()) {
            return;
        }

        $soldCodes = Product::whereIn('id', $removedIds)
            ->whereIn('id', app(ProductUnitService::class)->soldUnitIdsQuery())
            ->pluck('code');

        foreach ($soldCodes as $code) {
            $validator->errors()->add('products', "La unidad {$code} ya se vendió y no se puede quitar de la compra.");
        }
    }

    /**
     * `unit_code` (código manual) se reporta así; el resto bajo `unit.{key}`.
     */
    private function lineAttribute(string $prefix, string $attribute): string
    {
        return match (true) {
            $attribute === 'code' => "{$prefix}.unit_code",
            $attribute === 'product' => "{$prefix}.product_id",
            default => "{$prefix}.unit.".substr($attribute, strlen('details.')),
        };
    }

    private function requiresUnit(Product $product): bool
    {
        return $product->template instanceof ProductTemplate
            && $product->template->fields->where('level', ProductTemplateField::LEVEL_UNIT)->where('active', true)->isNotEmpty();
    }

    private function unitLabels(ProductTemplate $template): string
    {
        return $template->fields->where('level', ProductTemplateField::LEVEL_UNIT)->where('active', true)->pluck('label')->implode(', ');
    }

    /**
     * Solo los tipos que suman stock (factura, nota de venta, liquidación) registran unidades.
     */
    private function registersStock(mixed $voucherTypeId): bool
    {
        $slug = VoucherType::whereKey($voucherTypeId)->value('slug');

        return is_string($slug) && InventoryMovementTypes::forVoucher($slug) === InventoryMovementTypes::PURCHASE;
    }

    /**
     * @return Collection<int, int>
     */
    private function unitIdsOf(Shop $shop): Collection
    {
        return Product::whereIn('id', $shop->shopitems()->pluck('product_id'))
            ->whereNotNull('parent_product_id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id);
    }
}
