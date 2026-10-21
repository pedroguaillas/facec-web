<?php

use App\Models\Order\Order;
use App\Models\Product\Warehouse;
use App\Services\Product\InventoryLedgerService;
use App\Services\Product\VoucherInventoryService;
use App\StaticClasses\InventoryMovementTypes;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/InventoryTestHelpers.php';

/**
 * Regresión de N+1: las lecturas no deben crecer con la cantidad de líneas
 * y cada movimiento escrito debe costar como máximo 4 consultas (lock,
 * último saldo, insert, caché de stock) — el saldo es secuencial, no se
 * puede agrupar más.
 */
function countQueries(callable $callback): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $callback();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

/**
 * @return array<string, int>
 */
function inventoryQueryCounts(int $lines): array
{
    [$branch, , $warehouse] = voucherInventoryCompany(['allow_negative_stock' => true]);
    $other = Warehouse::factory()->create(['branch_id' => $branch->id]);
    $products = collect(range(1, $lines))->map(fn () => stockProduct($branch));
    $items = $products->map(fn ($product) => ['product_id' => $product->id, 'quantity' => 1])->all();

    $payload = invoicePayload($branch, $products[0], 1);
    $payload['products'] = $products->map(fn ($product) => [
        'product_id' => $product->id, 'quantity' => 1, 'price' => 10, 'discount' => 0, 'iva' => 0,
    ])->all();
    test()->postJson(route('orders.store'), $payload)->assertCreated();
    $order = Order::latest('id')->first();

    // Más filas de kardex para el producto 0, de distintos orígenes.
    foreach (range(1, $lines) as $day) {
        app(InventoryLedgerService::class)->registerMovement($products[0], $warehouse, InventoryMovementTypes::PURCHASE, 1, "2026-10-0{$day}", unitCost: 1);
    }

    return [
        'reverse' => countQueries(fn () => app(VoucherInventoryService::class)->reverse($order->fresh())),
        'adjustment' => countQueries(fn () => test()->postJson(route('inventory.adjustments'), [
            'direction' => 'in', 'date' => '2026-10-01', 'note' => 'Conteo', 'items' => $items,
        ])->assertCreated()),
        'opening' => countQueries(fn () => test()->postJson(route('inventory.opening-balance'), [
            'warehouse_id' => $other->id, 'date' => '2026-10-01', 'items' => $items,
        ])->assertCreated()),
        'transfer' => countQueries(fn () => test()->postJson(route('inventory.transfers'), [
            'from_warehouse_id' => $warehouse->id, 'to_warehouse_id' => $other->id, 'date' => '2026-10-01', 'items' => $items,
        ])->assertCreated()),
        'stock' => countQueries(fn () => test()->getJson(route('inventory.stock'))->assertSuccessful()),
        'kardex' => countQueries(fn () => test()->getJson(route('products.kardex', $products[0]))->assertSuccessful()),
        'valuation' => countQueries(fn () => test()->getJson(route('inventory.valuation'))->assertSuccessful()),
        'cost_of_sales' => countQueries(fn () => test()->getJson(route('inventory.cost-of-sales', ['from' => '2026-01-01', 'to' => '2026-12-31']))->assertSuccessful()),
    ];
}

test('reportes de inventario hacen las mismas consultas sin importar cuántas filas haya', function () {
    $few = inventoryQueryCounts(2);
    $many = inventoryQueryCounts(6);

    foreach (['stock', 'kardex', 'valuation', 'cost_of_sales'] as $report) {
        expect($many[$report])->toBe($few[$report], "{$report} crece con las filas (N+1)");
    }
});

test('cada movimiento escrito cuesta como máximo 4 consultas', function () {
    $few = inventoryQueryCounts(2);
    $many = inventoryQueryCounts(6);

    // 4 líneas de diferencia; la transferencia escribe 2 movimientos por línea.
    $movementsPerLine = ['reverse' => 1, 'adjustment' => 1, 'opening' => 1, 'transfer' => 2];

    foreach ($movementsPerLine as $operation => $movements) {
        $perMovement = ($many[$operation] - $few[$operation]) / (4 * $movements);

        expect($perMovement)->toBeLessThanOrEqual(4, "{$operation} hace {$perMovement} consultas por movimiento");
    }
});
