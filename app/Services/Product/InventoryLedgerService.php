<?php

namespace App\Services\Product;

use App\Models\Inventory;
use App\Models\Product\Product;
use App\Models\Product\Warehouse;
use App\StaticClasses\InventoryMovementTypes;

/**
 * Kardex real: cada movimiento queda con previous_balance/new_balance por
 * producto+bodega (mismo patrón que la cartera). No bloquea saldo negativo —
 * a diferencia de caja/banco, aquí es común migrar datos sin un conteo
 * inicial exacto; el saldo negativo queda como señal a corregir, no como
 * error duro.
 */
class InventoryLedgerService
{
    public function registerMovement(
        Product $product,
        Warehouse $warehouse,
        string $type,
        float $quantity,
        string $date,
        ?int $modelId = null,
        ?float $price = null,
        ?string $codeProvider = null,
    ): Inventory {
        // Lockea el producto para serializar escrituras concurrentes sobre su
        // saldo (se deriva del último movimiento, no hay columna de saldo que lockear).
        Product::where('id', $product->id)->lockForUpdate()->first();

        $last = Inventory::where('product_id', $product->id)
            ->where('warehouse_id', $warehouse->id)
            ->orderByDesc('id')
            ->first();

        $previousBalance = $last ? (float) $last->new_balance : 0.0;
        $delta = in_array($type, InventoryMovementTypes::INCREASE_TYPES, true) ? $quantity : -$quantity;
        $newBalance = $previousBalance + $delta;

        return Inventory::create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'model_id' => $modelId,
            'type' => $type,
            'quantity' => $quantity,
            'price' => $price,
            'previous_balance' => $previousBalance,
            'new_balance' => $newBalance,
            'date' => $date,
            'code_provider' => $codeProvider,
        ]);
    }
}
