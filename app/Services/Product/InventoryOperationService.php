<?php

namespace App\Services\Product;

use App\Models\Inventory;
use App\Models\Product\Product;
use App\Models\Product\Warehouse;
use App\StaticClasses\InventoryMovementTypes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Operaciones manuales sobre el kardex. Cada operación es atómica (todas sus
 * líneas o ninguna) y sus filas comparten `document_uuid`.
 */
class InventoryOperationService
{
    public function __construct(private InventoryLedgerService $ledger) {}

    /**
     * Carga el saldo inicial. Solo para productos sin movimientos previos en
     * esa bodega: después del primer movimiento, corregir es un ajuste.
     *
     * @param  array<int, array{product_id: int, quantity: float|int|string, unit_cost?: float|int|string|null}>  $items
     * @return Collection<int, Inventory>
     */
    public function openingBalance(Warehouse $warehouse, string $date, array $items, ?string $note = null): Collection
    {
        return DB::transaction(function () use ($warehouse, $date, $items, $note) {
            $uuid = (string) Str::uuid();
            $products = $this->products($items);
            $withMovements = Inventory::where('warehouse_id', $warehouse->id)
                ->whereIn('product_id', $products->keys())
                ->distinct()
                ->pluck('product_id')
                ->flip();

            return collect($items)->map(function (array $item, int $index) use ($warehouse, $date, $note, $uuid, $products, $withMovements) {
                $product = $products[$item['product_id']];

                if ($withMovements->has($product->id)) {
                    throw ValidationException::withMessages([
                        "items.{$index}.product_id" => "\"{$product->name}\" ya tiene movimientos en {$warehouse->name}; usa un ajuste.",
                    ]);
                }

                return $this->ledger->registerMovement(
                    $product,
                    $warehouse,
                    InventoryMovementTypes::OPENING_BALANCE,
                    (float) $item['quantity'],
                    $date,
                    unitCost: isset($item['unit_cost']) ? (float) $item['unit_cost'] : null,
                    note: $note,
                    documentUuid: $uuid,
                );
            });
        });
    }

    /**
     * Ajuste manual de ingreso o salida (rotura, conteo físico, etc.). Un
     * ingreso sin `unit_cost` entra al costo promedio vigente.
     *
     * @param  array<int, array{product_id: int, quantity: float|int|string, unit_cost?: float|int|string|null}>  $items
     * @return Collection<int, Inventory>
     */
    public function adjustment(Warehouse $warehouse, string $direction, string $date, string $note, array $items): Collection
    {
        $type = $direction === InventoryMovementTypes::IN
            ? InventoryMovementTypes::ADJUSTMENT_IN
            : InventoryMovementTypes::ADJUSTMENT_OUT;

        return DB::transaction(function () use ($warehouse, $type, $date, $note, $items) {
            $uuid = (string) Str::uuid();
            $products = $this->products($items);

            return collect($items)->map(fn (array $item) => $this->ledger->registerMovement(
                $products[$item['product_id']],
                $warehouse,
                $type,
                (float) $item['quantity'],
                $date,
                unitCost: $type === InventoryMovementTypes::ADJUSTMENT_IN && isset($item['unit_cost']) ? (float) $item['unit_cost'] : null,
                note: $note,
                documentUuid: $uuid,
            ));
        });
    }

    /**
     * Mueve stock entre dos bodegas: una salida en el origen y una entrada en
     * el destino, al mismo costo con el que salió.
     *
     * @param  array<int, array{product_id: int, quantity: float|int|string}>  $items
     * @return Collection<int, Inventory>
     */
    public function transfer(Warehouse $from, Warehouse $to, string $date, array $items, ?string $note = null): Collection
    {
        $note ??= "Transferencia {$from->name} → {$to->name}";

        return DB::transaction(function () use ($from, $to, $date, $items, $note) {
            $uuid = (string) Str::uuid();
            $products = $this->products($items);

            return collect($items)->flatMap(function (array $item) use ($from, $to, $date, $note, $uuid, $products) {
                $product = $products[$item['product_id']];
                $quantity = (float) $item['quantity'];

                $out = $this->ledger->registerMovement(
                    $product, $from, InventoryMovementTypes::TRANSFER_OUT, $quantity, $date,
                    note: $note, documentUuid: $uuid,
                );

                $in = $this->ledger->registerMovement(
                    $product, $to, InventoryMovementTypes::TRANSFER_IN, $quantity, $date,
                    unitCost: $out->unit_cost, note: $note, documentUuid: $uuid,
                );

                return [$out, $in];
            });
        });
    }

    /**
     * @param  array<int, array{product_id: int}>  $items
     * @return Collection<int, Product>
     */
    private function products(array $items): Collection
    {
        return Product::whereIn('id', array_column($items, 'product_id'))->get()->keyBy('id');
    }
}
