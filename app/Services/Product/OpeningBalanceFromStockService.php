<?php

namespace App\Services\Product;

use App\Models\Branch;
use App\Models\Product\Product;
use App\StaticClasses\InventoryMovementTypes;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Convierte el `products.stock` tipeado a mano (o importado por Excel) en un
 * movimiento "Inventario inicial" en la bodega por defecto, para que el
 * kardex arranque desde ese saldo. Solo toca productos sin movimientos en
 * ninguna bodega, así que es idempotente.
 *
 * Se usa al activar el inventario en la empresa, al crear/importar productos
 * con stock y desde `inventory:seed-opening-balances` (datos ya existentes).
 */
class OpeningBalanceFromStockService
{
    const NOTE = 'Inventario inicial desde el stock del producto';

    public function __construct(
        private InventoryLedgerService $ledger,
        private WarehouseResolver $warehouseResolver,
    ) {}

    /**
     * @param  array<string, float>  $unitCostsByCode  Costo unitario por código (columna "Costo" del Excel); sin costo queda en 0.
     * @return int Productos a los que se les registró saldo inicial.
     */
    public function forBranch(Branch $branch, array $unitCostsByCode = []): int
    {
        return DB::transaction(function () use ($branch, $unitCostsByCode) {
            $products = $this->pendingProducts()->where('branch_id', $branch->id)->get();

            if ($products->isEmpty()) {
                return 0;
            }

            $warehouse = $this->warehouseResolver->forBranch($branch);
            $date = Carbon::today()->toDateString();

            foreach ($products as $product) {
                $this->ledger->registerMovement(
                    $product, $warehouse, InventoryMovementTypes::OPENING_BALANCE,
                    (float) $product->stock, $date, unitCost: $unitCostsByCode[$product->code] ?? null, note: self::NOTE,
                );
            }

            return $products->count();
        });
    }

    public function forProduct(Product $product): void
    {
        if (! $this->pendingProducts()->whereKey($product->id)->exists()) {
            return;
        }

        $branch = Branch::findOrFail($product->branch_id);

        $this->ledger->registerMovement(
            $product, $this->warehouseResolver->forBranch($branch), InventoryMovementTypes::OPENING_BALANCE,
            (float) $product->stock, Carbon::today()->toDateString(), note: self::NOTE,
        );
    }

    /**
     * @return Builder<Product>
     */
    private function pendingProducts(): Builder
    {
        return Product::withoutGlobalScope('branch')
            ->stockable()
            ->where('stock', '>', 0)
            ->whereDoesntHave('inventories', fn (Builder $query) => $query->whereNotNull('warehouse_id'));
    }
}
