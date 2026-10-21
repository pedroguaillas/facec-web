<?php

namespace App\Services\Product;

use App\Models\Branch;
use App\Models\Inventory;
use App\Models\Order\Order;
use App\Models\Product\Product;
use App\Models\Shop\Shop;
use App\StaticClasses\InventoryMovementTypes;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Traduce un comprobante (venta/compra) en movimientos de kardex según
 * InventoryMovementTypes::VOUCHER_MOVEMENTS.
 *
 * sync() sirve tanto para crear como para editar: si las líneas vigentes
 * del kardex ya coinciden con las del documento no hace nada; si no,
 * revierte las vigentes y registra las nuevas. Así una edición nunca deja
 * movimientos huérfanos y el kardex conserva la traza completa.
 */
class VoucherInventoryService
{
    public function __construct(
        private InventoryLedgerService $ledger,
        private WarehouseResolver $warehouseResolver,
    ) {}

    /**
     * @param  bool  $isNew  Documento recién creado: no tiene movimientos, se evita consultarlos.
     */
    public function syncOrder(Order $order, bool $isNew = false): void
    {
        $this->sync(
            $order,
            $isNew,
            $order->voucherType->slug,
            $order->orderitems()->get(['product_id', 'quantity', 'conversion_factor', 'price', 'discount'])->all(),
        );
    }

    /**
     * @param  bool  $isNew  Documento recién creado: no tiene movimientos, se evita consultarlos.
     */
    public function syncShop(Shop $shop, bool $isNew = false): void
    {
        $this->sync(
            $shop,
            $isNew,
            $shop->voucherType->slug,
            $shop->shopitems()->get(['product_id', 'quantity', 'conversion_factor', 'price', 'discount'])->all(),
        );
    }

    /**
     * Anulación del documento: revierte todo lo que movió, con fecha de hoy.
     */
    public function reverse(Order|Shop $document): void
    {
        $this->ledger->reverseFor($document, Carbon::today()->toDateString(), "Anulación {$document->serie}");
    }

    /**
     * @param  array<int, Model>  $items
     */
    private function sync(Order|Shop $document, bool $isNew, string $voucherSlug, array $items): void
    {
        $branch = Branch::with('company')->find($document->branch_id);

        if (! $branch?->company?->inventory) {
            return;
        }

        $type = InventoryMovementTypes::forVoucher($voucherSlug);
        $date = Carbon::parse($document->date)->toDateString();
        $warehouse = $this->warehouseResolver->forBranch($branch);

        $lines = $type ? $this->stockLines($items) : [];

        $active = $isNew ? new Collection : $this->ledger->activeFor($document);

        if ($this->matchesActiveMovements($active, (string) $type, $lines, $date, $warehouse->id)) {
            return;
        }

        $this->ledger->reverseMovements($active, $date, "Edición {$document->serie}");

        foreach ($lines as $line) {
            $this->ledger->registerMovement(
                $line['product'],
                $warehouse,
                $type,
                $line['quantity'],
                $date,
                source: $document,
                price: $line['price'],
                // Compras fijan el costo (neto del descuento de la línea);
                // ventas y devoluciones salen/entran al costo promedio vigente.
                unitCost: $type === InventoryMovementTypes::PURCHASE ? $line['net_unit_price'] : null,
            );
        }
    }

    /**
     * Solo productos (no servicios) con cantidad > 0, convertidas a unidad
     * base con el `conversion_factor` de la línea; precio y costo, por unidad base.
     *
     * @param  array<int, Model>  $items
     * @return array<int, array{product: Product, quantity: float, price: float, net_unit_price: float}>
     */
    private function stockLines(array $items): array
    {
        $products = Product::withoutGlobalScopes()
            ->whereIn('id', array_map(fn ($item) => $item->product_id, $items))
            ->stockable()
            ->get()
            ->keyBy('id');

        $lines = [];

        foreach ($items as $item) {
            $product = $products[$item->product_id] ?? null;
            // La línea está en su presentación (ej. 1 cubeta); el kardex, en unidades base (30 huevos).
            $factor = (float) ($item->conversion_factor ?: 1);
            $quantity = (float) $item->quantity * $factor;

            if (! $product || $quantity <= 0) {
                continue;
            }

            $lineTotal = (float) $item->price * (float) $item->quantity;

            $lines[] = [
                'product' => $product,
                'quantity' => $quantity,
                'price' => (float) $item->price / $factor,
                'net_unit_price' => max(($lineTotal - (float) $item->discount) / $quantity, 0),
            ];
        }

        return $lines;
    }

    /**
     * @param  Collection<int, Inventory>  $active
     * @param  array<int, array{product: Product, quantity: float, price: float, net_unit_price: float}>  $lines
     */
    private function matchesActiveMovements(Collection $active, string $type, array $lines, string $date, int $warehouseId): bool
    {
        $key = fn (string $lineType, int $productId, float $quantity, float $price, string $lineDate, int $lineWarehouse) => implode('|', [$lineType, $productId, round($quantity, 6), round($price, 6), $lineDate, $lineWarehouse]);

        $current = $active->map(fn (Inventory $movement) => $key(
            $movement->type,
            $movement->product_id,
            $movement->quantity,
            (float) $movement->price,
            Carbon::parse($movement->date)->toDateString(),
            $movement->warehouse_id,
        ))->sort()->values()->all();

        $desired = collect($lines)->map(fn (array $line) => $key(
            (string) $type,
            $line['product']->id,
            $line['quantity'],
            $line['price'],
            $date,
            $warehouseId,
        ))->sort()->values()->all();

        return $current === $desired;
    }
}
