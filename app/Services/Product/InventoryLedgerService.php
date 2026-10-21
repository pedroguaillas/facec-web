<?php

namespace App\Services\Product;

use App\Models\Branch;
use App\Models\Inventory;
use App\Models\Product\Product;
use App\Models\Product\Warehouse;
use App\StaticClasses\InventoryMovementTypes;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Kardex real: cada movimiento queda con previous_balance/new_balance por
 * producto+bodega (mismo patrón que la cartera) y con costo promedio
 * ponderado (`average_cost`, `balance_value`). El kardex solo se inserta:
 * anular/editar un documento genera reversas (ver reverseFor()).
 *
 * Saldo negativo: una salida que deje la bodega en negativo se rechaza
 * (422) salvo que la empresa tenga `allow_negative_stock`. Las reversas
 * nunca se bloquean — son correcciones de algo que ya ocurrió.
 *
 * Tras cada movimiento se recalcula `products.stock` como caché: suma del
 * saldo de todas las bodegas del producto.
 *
 * Solo productos inventariables (`Product::isStockable()`): registrar un
 * servicio es un error de programación y lanza InvalidArgumentException.
 *
 * Las consultas internas van sin global scopes: los ids ya vienen resueltos
 * y el servicio también corre fuera de una request (comandos, admin).
 */
class InventoryLedgerService
{
    /** @var array<int, bool> */
    private array $allowsNegativeByBranch = [];

    public function registerMovement(
        Product $product,
        Warehouse $warehouse,
        string $type,
        float $quantity,
        string $date,
        ?Model $source = null,
        ?float $price = null,
        ?float $unitCost = null,
        ?string $note = null,
        ?string $documentUuid = null,
    ): Inventory {
        return $this->write(
            product: $product,
            warehouse: $warehouse,
            type: $type,
            direction: InventoryMovementTypes::directionOf($type),
            quantity: $quantity,
            date: $date,
            attributes: [
                'model_type' => $source?->getMorphClass(),
                'model_id' => $source?->getKey(),
                'price' => $price,
                'note' => $note,
                'document_uuid' => $documentUuid,
            ],
            unitCost: $unitCost,
            enforceStock: true,
        );
    }

    /**
     * Movimientos vigentes de un documento, con producto y bodega precargados
     * (sin global scopes) para revertirlos sin una consulta por fila.
     *
     * @return Collection<int, Inventory>
     */
    public function activeFor(Model $source): Collection
    {
        $withoutScopes = fn ($query) => $query->withoutGlobalScopes();

        return Inventory::active()
            ->where('model_type', $source->getMorphClass())
            ->where('model_id', $source->getKey())
            // Filas históricas sin bodega quedaron fuera del saldo: no se revierten.
            ->whereNotNull('warehouse_id')
            ->with(['product' => $withoutScopes, 'warehouse' => $withoutScopes])
            ->orderBy('id')
            ->get();
    }

    /**
     * Revierte todos los movimientos vigentes de un documento (venta/compra).
     * Idempotente: lo ya revertido no se vuelve a revertir.
     *
     * @return int Cantidad de movimientos revertidos.
     */
    public function reverseFor(Model $source, string $date, ?string $note = null): int
    {
        return $this->reverseMovements($this->activeFor($source), $date, $note);
    }

    /**
     * @param  Collection<int, Inventory>  $movements
     */
    public function reverseMovements(Collection $movements, string $date, ?string $note = null): int
    {
        foreach ($movements as $movement) {
            $this->reverse($movement, $date, $note);
        }

        return $movements->count();
    }

    public function reverse(Inventory $movement, string $date, ?string $note = null): Inventory
    {
        $product = $movement->relationLoaded('product')
            ? $movement->product
            : Product::withoutGlobalScopes()->findOrFail($movement->product_id);
        $warehouse = $movement->relationLoaded('warehouse')
            ? $movement->warehouse
            : Warehouse::withoutGlobalScopes()->findOrFail($movement->warehouse_id);

        return $this->write(
            product: $product,
            warehouse: $warehouse,
            type: InventoryMovementTypes::REVERSAL,
            direction: $movement->direction === InventoryMovementTypes::IN ? InventoryMovementTypes::OUT : InventoryMovementTypes::IN,
            quantity: $movement->quantity,
            date: $date,
            attributes: [
                'model_type' => $movement->model_type,
                'model_id' => $movement->model_id,
                'price' => $movement->price,
                'reverses_id' => $movement->id,
                'note' => $note,
            ],
            // Revertir una entrada saca su propio costo (no el promedio), así
            // el promedio vuelve a quedar como si la entrada no hubiera existido.
            unitCost: $movement->unit_cost,
            enforceStock: false,
        );
    }

    /**
     * Saldo actual de un producto en una bodega.
     */
    public function balance(int $productId, int $warehouseId): float
    {
        return (float) (Inventory::where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->orderByDesc('id')
            ->value('new_balance') ?? 0);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function write(
        Product $product,
        Warehouse $warehouse,
        string $type,
        string $direction,
        float $quantity,
        string $date,
        array $attributes,
        ?float $unitCost,
        bool $enforceStock,
    ): Inventory {
        if (! $product->isStockable()) {
            throw new InvalidArgumentException("El servicio \"{$product->name}\" no maneja inventario.");
        }

        // Lockea el producto para serializar escrituras concurrentes sobre su
        // saldo (se deriva del último movimiento, no hay columna de saldo que lockear).
        Product::withoutGlobalScopes()->whereKey($product->id)->lockForUpdate()->first();

        $last = Inventory::where('product_id', $product->id)
            ->where('warehouse_id', $warehouse->id)
            ->orderByDesc('id')
            ->first();

        // Cantidades y saldos a 6 decimales (precisión de las columnas): evita
        // que el ruido de float (0.3 − 0.1 − 0.2 = −2.7e-17) dispare un falso
        // "stock insuficiente" o deje saldos residuales.
        $quantity = round($quantity, 6);
        $previousBalance = $last ? (float) $last->new_balance : 0.0;
        $previousAverage = (float) ($last->average_cost ?? 0);
        $previousValue = $last?->balance_value !== null ? (float) $last->balance_value : $previousBalance * $previousAverage;

        if ($direction === InventoryMovementTypes::IN) {
            $cost = $unitCost ?? $previousAverage;
            $newBalance = round($previousBalance + $quantity, 6);

            // Sin saldo previo positivo no hay promedio que ponderar.
            if ($previousBalance <= 0) {
                $average = $cost;
                $value = $newBalance * $cost;
            } else {
                $value = $previousValue + $quantity * $cost;
                $average = $value / $newBalance;
            }
        } else {
            $cost = $unitCost ?? $previousAverage;
            $newBalance = round($previousBalance - $quantity, 6);

            if ($enforceStock && $newBalance < 0 && ! $this->allowsNegativeStock($warehouse)) {
                throw ValidationException::withMessages([
                    'products' => sprintf(
                        'Stock insuficiente de "%s" en %s: disponible %s, requerido %s.',
                        $product->name,
                        $warehouse->name,
                        $this->formatQuantity($previousBalance),
                        $this->formatQuantity($quantity),
                    ),
                ]);
            }

            if ($newBalance > 0) {
                $value = max($previousValue - $quantity * $cost, 0);
                $average = $value / $newBalance;
            } else {
                $average = $previousAverage;
                $value = $newBalance * $previousAverage;
            }
        }

        $movement = Inventory::create([
            ...$attributes,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'type' => $type,
            'direction' => $direction,
            'quantity' => $quantity,
            'unit_cost' => round($cost, 6),
            'previous_balance' => $previousBalance,
            'new_balance' => $newBalance,
            'average_cost' => round($average, 6),
            'balance_value' => round($value, 4),
            'date' => $date,
            'user_id' => Auth::id(),
        ]);

        $this->refreshProductStock($product->id);

        return $movement;
    }

    /**
     * `products.stock` = suma del último saldo de cada bodega del producto,
     * en una sola sentencia (UPDATE con subconsulta sobre `inventories`).
     */
    private function refreshProductStock(int $productId): void
    {
        $latestIds = Inventory::selectRaw('MAX(id)')
            ->where('product_id', $productId)
            ->whereNotNull('warehouse_id')
            ->groupBy('warehouse_id');

        $stock = Inventory::selectRaw('COALESCE(SUM(new_balance), 0)')->whereIn('id', $latestIds);

        Product::withoutGlobalScopes()->whereKey($productId)->update(['stock' => $stock]);
    }

    /**
     * Memoizado por sucursal: en empresas que permiten negativo, cada salida
     * que queda en negativo pasaría por aquí.
     */
    private function allowsNegativeStock(Warehouse $warehouse): bool
    {
        return $this->allowsNegativeByBranch[$warehouse->branch_id] ??= (bool) Branch::with('company')
            ->find($warehouse->branch_id)?->company?->allow_negative_stock;
    }

    private function formatQuantity(float $quantity): string
    {
        return rtrim(rtrim(number_format($quantity, 6, '.', ''), '0'), '.');
    }
}
