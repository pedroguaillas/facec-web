<?php

namespace App\Services\Product;

use App\Models\Order\Order;
use App\Models\Product\Product;
use App\Models\Product\ProductTemplate;
use App\Models\Product\ProductTemplateField;
use App\StaticClasses\InventoryMovementTypes;
use App\StaticClasses\VoucherTypes;
use Illuminate\Support\Collection;

/**
 * Ventas de unidades (motos) y foto de características en las líneas. Ver
 * docs/caracteristicas-producto.md §5 y §7.
 */
class UnitSaleService
{
    public function __construct(private ProductUnitService $units) {}

    /**
     * Errores de las líneas de una venta, con claves `products.N.campo`:
     *  - unidad: cantidad 1, sin presentación, sin repetirse en otra línea;
     *    en factura/nota de venta debe estar disponible (con inventario,
     *    stock > 0; sin inventario, no estar en otra venta vigente);
     *  - modelo con campos de nivel `unidad`: en factura/nota de venta se
     *    vende la unidad, no el modelo (la proforma sí cotiza el modelo).
     * La nota de crédito no se valida (devuelve lo vendido).
     *
     * @param  array<int, mixed>  $lines
     * @return array<string, string>
     */
    public function lineErrors(array $lines, string $voucherSlug, bool $inventoryEnabled, ?Order $order = null): array
    {
        if ($voucherSlug === VoucherTypes::VENTA_NOTA_CREDITO) {
            return [];
        }

        $isSale = InventoryMovementTypes::forVoucher($voucherSlug) === InventoryMovementTypes::SALE;
        $products = Product::with('template.fields')
            ->whereIn('id', collect($lines)->pluck('product_id')->filter(fn ($id) => is_numeric($id)))
            ->get()
            ->keyBy('id');
        $unavailableIds = $isSale ? $this->unavailableUnitIds($products, $inventoryEnabled, $order) : collect();
        $errors = [];
        $seen = [];

        foreach ($lines as $index => $line) {
            $product = is_array($line) ? $products->get((int) ($line['product_id'] ?? 0)) : null;

            if (! $product) {
                continue;
            }

            if (! $product->parent_product_id) {
                if ($isSale && $this->requiresUnit($product->template)) {
                    $errors["products.{$index}.product_id"] = "Selecciona la unidad de {$product->name} ({$this->unitLabels($product->template)}).";
                }

                continue;
            }

            if ((float) ($line['quantity'] ?? 0) !== 1.0) {
                $errors["products.{$index}.quantity"] = 'Cada unidad se vende en su propia línea con cantidad 1.';
            }

            if (filled($line['presentation_id'] ?? null)) {
                $errors["products.{$index}.presentation_id"] = 'Una unidad no se vende por presentación.';
            }

            if (isset($seen[$product->id])) {
                $errors["products.{$index}.product_id"] = "La unidad {$product->code} está repetida en otra línea.";
            } elseif ($unavailableIds->contains($product->id)) {
                $errors["products.{$index}.product_id"] = $inventoryEnabled
                    ? "La unidad {$product->code} no está en stock."
                    : "La unidad {$product->code} ya se vendió.";
            }

            $seen[$product->id] = true;
        }

        return $errors;
    }

    /**
     * Características efectivas de cada producto, por id (solo los que
     * tienen alguna): foto de la venta y XML/PDF de liquidación y guía.
     *
     * @param  iterable<int|string>  $productIds
     * @return array<int, array<int, array{label: string, value: string, in_sri: bool, sort: int}>>
     */
    public function characteristicsByProduct(iterable $productIds): array
    {
        return Product::withTrashed()
            ->whereIn('id', collect($productIds)->unique()->values())
            ->where(fn ($query) => $query->whereNotNull('product_template_id')->orWhereNotNull('parent_product_id'))
            ->with(['details.field', 'parentProduct.details.field'])
            ->get()
            ->mapWithKeys(fn (Product $product) => [$product->id => $product->characteristics()->all()])
            ->filter()
            ->all();
    }

    /**
     * Sin inventario el stock de una unidad no lo mueve el kardex: se deja
     * en 0 si está en una venta vigente y en 1 si no (venta anulada o
     * devuelta con nota de crédito).
     *
     * @param  iterable<int|string>  $productIds
     */
    public function syncStockWithoutInventory(iterable $productIds, bool $inventoryEnabled): void
    {
        if ($inventoryEnabled) {
            return;
        }

        $unitIds = Product::whereIn('id', collect($productIds)->unique()->values())->whereNotNull('parent_product_id')->pluck('id');

        if ($unitIds->isEmpty()) {
            return;
        }

        $soldIds = Product::whereIn('id', $unitIds)->whereIn('id', $this->units->soldUnitIdsQuery())->pluck('id');

        Product::whereIn('id', $soldIds)->update(['stock' => 0]);
        Product::whereIn('id', $unitIds->diff($soldIds))->update(['stock' => 1]);
    }

    /**
     * Unidades de las líneas que no se pueden vender. Las que ya estaban en
     * la venta que se edita siguen disponibles para ella.
     *
     * @param  Collection<int, Product>  $products
     * @return Collection<int, int>
     */
    private function unavailableUnitIds(Collection $products, bool $inventoryEnabled, ?Order $order): Collection
    {
        $units = $products->whereNotNull('parent_product_id');
        $ownIds = $order ? $order->orderitems()->pluck('product_id')->map(fn ($id) => (int) $id) : collect();

        if ($inventoryEnabled) {
            return $units->filter(fn (Product $unit) => (float) $unit->stock <= 0 && ! $ownIds->contains($unit->id))
                ->keys()
                ->map(fn ($id) => (int) $id);
        }

        return Product::whereIn('id', $units->keys())
            ->whereIn('id', $this->units->soldUnitIdsQuery($order?->id))
            ->pluck('id')
            ->map(fn ($id) => (int) $id);
    }

    private function requiresUnit(?ProductTemplate $template): bool
    {
        return $template instanceof ProductTemplate
            && $template->fields->where('level', ProductTemplateField::LEVEL_UNIT)->where('active', true)->isNotEmpty();
    }

    private function unitLabels(ProductTemplate $template): string
    {
        return $template->fields->where('level', ProductTemplateField::LEVEL_UNIT)->where('active', true)->pluck('label')->implode(', ');
    }
}
