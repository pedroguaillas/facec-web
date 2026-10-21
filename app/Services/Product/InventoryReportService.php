<?php

namespace App\Services\Product;

use App\Models\Inventory;
use App\Models\Product\Product;
use App\Models\Product\Warehouse;
use App\StaticClasses\InventoryMovementTypes;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Lecturas del kardex. Todas se limitan a las bodegas visibles por el
 * usuario (Warehouse aplica BranchScope; `inventories` no tiene branch_id).
 * El saldo de un producto en una bodega es el `new_balance` de su última
 * fila (mayor id).
 */
class InventoryReportService
{
    /**
     * Stock actual por producto, con el detalle por bodega.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function stock(?string $search, ?int $warehouseId, bool $belowMin, int $perPage): LengthAwarePaginator
    {
        $warehouses = $this->warehouses($warehouseId);

        $products = $this->stockProductsQuery($search, $belowMin)
            ->paginate($perPage, ['id', 'code', 'name', 'stock', 'min_stock'])
            ->withQueryString();

        $balances = $this->latestBalances($products->pluck('id')->all(), $warehouses->keys()->all())
            ->groupBy('product_id');

        return $products->through(fn (Product $product) => $this->stockRow($product, $balances, $warehouses));
    }

    /**
     * Mismo reporte que stock(), sin paginar (exportación).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function stockAll(?string $search, ?int $warehouseId, bool $belowMin): Collection
    {
        $warehouses = $this->warehouses($warehouseId);
        $products = $this->stockProductsQuery($search, $belowMin)->get(['id', 'code', 'name', 'stock', 'min_stock']);

        // Todas las bodegas de una vez: un whereIn con miles de ids no aporta.
        $balances = $this->latestBalances(null, $warehouses->keys()->all())->groupBy('product_id');

        return $products->map(fn (Product $product) => $this->stockRow($product, $balances, $warehouses));
    }

    /**
     * Kardex de un producto, en orden de registro.
     *
     * @return Builder<Inventory>
     */
    public function kardexQuery(Product $product, ?int $warehouseId, ?string $from, ?string $to): Builder
    {
        return Inventory::where('product_id', $product->id)
            ->whereIn('warehouse_id', Warehouse::when($warehouseId, fn ($query, $id) => $query->whereKey($id))->select('id'))
            ->when($from, fn ($query) => $query->where('date', '>=', $from))
            ->when($to, fn ($query) => $query->where('date', '<=', $to))
            ->with(['warehouse:id,name', 'user:id,name', 'source'])
            ->orderBy('id');
    }

    /**
     * @return Builder<Product>
     */
    private function stockProductsQuery(?string $search, bool $belowMin): Builder
    {
        return Product::stockable()
            ->when($search, function ($query) use ($search) {
                $escaped = str_replace(['%', '_'], ['\%', '\_'], $search);

                $query->where(fn ($q) => $q->where('code', 'LIKE', "%{$escaped}%")->orWhere('name', 'LIKE', "%{$escaped}%"));
            })
            // `stock` es la caché total (todas las bodegas).
            ->when($belowMin, fn ($query) => $query->whereNotNull('min_stock')->whereRaw('COALESCE(stock, 0) < min_stock'))
            ->orderBy('name');
    }

    /**
     * @param  Collection<int, Collection<int, Inventory>>  $balances
     * @param  Collection<int, Warehouse>  $warehouses
     * @return array<string, mixed>
     */
    private function stockRow(Product $product, Collection $balances, Collection $warehouses): array
    {
        $rows = $balances->get($product->id, collect());
        $quantity = (float) $rows->sum('new_balance');
        $value = (float) $rows->sum('balance_value');

        return [
            'id' => $product->id,
            'code' => $product->code,
            'name' => $product->name,
            'min_stock' => $product->min_stock,
            'stock' => $quantity,
            'below_min' => $product->min_stock !== null && (float) $product->stock < $product->min_stock,
            'average_cost' => $quantity > 0 ? round($value / $quantity, 6) : 0.0,
            'value' => round($value, 2),
            'warehouses' => $rows->map(fn (Inventory $row) => [
                'warehouse_id' => $row->warehouse_id,
                'name' => $warehouses[$row->warehouse_id]->name,
                'stock' => $row->new_balance,
                'average_cost' => $row->average_cost,
                'value' => round((float) $row->balance_value, 2),
            ])->values(),
        ];
    }

    /**
     * Valor del inventario a una fecha (incluida), al costo promedio.
     *
     * @return array{date: string, total_value: float, items: Collection<int, array<string, mixed>>}
     */
    public function valuation(string $date, ?int $warehouseId): array
    {
        $warehouses = $this->warehouses($warehouseId);
        $balances = $this->latestBalances(null, $warehouses->keys()->all(), $date)->groupBy('product_id');
        $products = Product::withTrashed()->whereIn('id', $balances->keys())->get(['id', 'code', 'name'])->keyBy('id');

        $items = $balances->map(function (Collection $rows, int $productId) use ($products) {
            $quantity = (float) $rows->sum('new_balance');
            $value = (float) $rows->sum('balance_value');

            return [
                'product_id' => $productId,
                'code' => $products[$productId]->code ?? null,
                'name' => $products[$productId]->name ?? null,
                'quantity' => $quantity,
                'average_cost' => $quantity > 0 ? round($value / $quantity, 6) : 0.0,
                'value' => round($value, 2),
            ];
        })
            ->filter(fn (array $item) => $item['quantity'] != 0)
            ->sortBy('name')
            ->values();

        return [
            'date' => $date,
            'total_value' => round((float) $items->sum('value'), 2),
            'items' => $items,
        ];
    }

    /**
     * Costo de ventas y utilidad bruta por producto en un rango de fechas.
     * Solo movimientos vigentes (una venta anulada no cuenta). `revenue` usa
     * el precio de la línea antes del descuento.
     *
     * @return array{from: string, to: string, totals: array<string, float>, items: Collection<int, array<string, mixed>>}
     */
    public function costOfSales(string $from, string $to, ?int $warehouseId): array
    {
        $sale = InventoryMovementTypes::SALE;

        $rows = Inventory::active()
            ->whereIn('warehouse_id', $this->warehouses($warehouseId)->keys())
            ->whereIn('product_id', $this->stockableProductIds())
            ->whereIn('type', [InventoryMovementTypes::SALE, InventoryMovementTypes::SALE_RETURN])
            ->whereBetween('date', [$from, $to])
            ->groupBy('product_id')
            ->selectRaw('product_id')
            ->selectRaw('SUM(CASE WHEN type = ? THEN quantity ELSE -quantity END) AS quantity', [$sale])
            ->selectRaw('SUM(CASE WHEN type = ? THEN quantity * unit_cost ELSE -quantity * unit_cost END) AS cost', [$sale])
            ->selectRaw('SUM(CASE WHEN type = ? THEN quantity * COALESCE(price, 0) ELSE -quantity * COALESCE(price, 0) END) AS revenue', [$sale])
            ->get();

        $products = Product::withTrashed()->whereIn('id', $rows->pluck('product_id'))->get(['id', 'code', 'name'])->keyBy('id');

        $items = $rows->map(fn ($row) => [
            'product_id' => $row->product_id,
            'code' => $products[$row->product_id]->code ?? null,
            'name' => $products[$row->product_id]->name ?? null,
            'quantity' => round((float) $row->quantity, 6),
            'cost' => round((float) $row->cost, 2),
            'revenue' => round((float) $row->revenue, 2),
            'gross_profit' => round((float) $row->revenue - (float) $row->cost, 2),
        ])->sortBy('name')->values();

        return [
            'from' => $from,
            'to' => $to,
            'totals' => [
                'cost' => round((float) $items->sum('cost'), 2),
                'revenue' => round((float) $items->sum('revenue'), 2),
                'gross_profit' => round((float) $items->sum('gross_profit'), 2),
            ],
            'items' => $items,
        ];
    }

    /**
     * @return Collection<int, Warehouse>
     */
    private function warehouses(?int $warehouseId): Collection
    {
        return Warehouse::when($warehouseId, fn ($query) => $query->whereKey($warehouseId))
            ->get(['id', 'name'])
            ->keyBy('id');
    }

    /**
     * Subconsulta de ids de productos inventariables (incluye eliminados, que
     * siguen siendo parte del valor histórico). Excluye servicios con
     * movimientos heredados de antes del filtro por tipo.
     *
     * @return Builder<Product>
     */
    private function stockableProductIds(): Builder
    {
        return Product::withoutGlobalScopes()->stockable()->select('products.id');
    }

    /**
     * Última fila por producto+bodega (opcionalmente hasta una fecha).
     *
     * @param  array<int, int>|null  $productIds
     * @param  array<int, int>  $warehouseIds
     * @return Collection<int, Inventory>
     */
    private function latestBalances(?array $productIds, array $warehouseIds, ?string $untilDate = null): Collection
    {
        $latestIds = Inventory::selectRaw('MAX(id)')
            ->whereIn('warehouse_id', $warehouseIds)
            ->when($productIds !== null, fn ($query) => $query->whereIn('product_id', $productIds))
            ->when($untilDate, fn ($query) => $query->where('date', '<=', $untilDate))
            ->groupBy('product_id', 'warehouse_id');

        return Inventory::whereIn('id', $latestIds)
            ->whereIn('product_id', $this->stockableProductIds())
            ->get(['id', 'product_id', 'warehouse_id', 'new_balance', 'average_cost', 'balance_value']);
    }
}
