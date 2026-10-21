<?php

use App\Exports\InventoryCostOfSalesExport;
use App\Exports\InventoryKardexExport;
use App\Exports\InventoryStockExport;
use App\Exports\InventoryValuationExport;
use App\Exports\ShopExport;
use App\Models\Product\Product;
use App\Models\Product\Warehouse;
use App\Models\Shop\Shop;
use App\Services\Product\InventoryLedgerService;
use App\StaticClasses\InventoryMovementTypes;
use Maatwebsite\Excel\Facades\Excel;

require_once __DIR__.'/InventoryTestHelpers.php';

beforeEach(function () {
    Excel::fake();

    [$this->branch, , $this->warehouse] = voucherInventoryCompany();
    $this->product = stockProduct($this->branch);
    $this->product->update(['code' => 'P-01', 'name' => 'Cemento', 'min_stock' => 20]);

    $ledger = app(InventoryLedgerService::class);
    $ledger->registerMovement($this->product, $this->warehouse, InventoryMovementTypes::PURCHASE, 10, '2026-10-01', unitCost: 2);
    $ledger->registerMovement($this->product, $this->warehouse, InventoryMovementTypes::SALE, 4, '2026-10-02', price: 5);
});

test('stock exporta una fila por producto y bodega con total', function () {
    $other = Warehouse::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Local 2']);
    app(InventoryLedgerService::class)->registerMovement($this->product, $other, InventoryMovementTypes::PURCHASE, 1, '2026-10-03', unitCost: 2);

    test()->get(route('inventory.stock.export'))->assertSuccessful();

    Excel::assertDownloaded('inventario-stock-'.now()->toDateString().'.xlsx', function (InventoryStockExport $export) {
        $rows = $export->array();

        return count($rows) === 3
            && $rows[0][0] === 'P-01'
            && $rows[0][5] === 'Sí'
            && $rows[2] === ['', 'Total', '', '', '', '', '', 14.0];
    });
});

test('kardex exporta encabezado del producto y movimientos con entrada y salida', function () {
    test()->get(route('products.kardex.export', $this->product))->assertSuccessful();

    Excel::assertDownloaded('kardex-P-01.xlsx', function (InventoryKardexExport $export) {
        $rows = $export->array();

        return $rows[0] === ['Producto', 'P-01 - Cemento']
            && $rows[3][1] === InventoryMovementTypes::PURCHASE && $rows[3][5] === 10.0 && $rows[3][6] === null
            && $rows[4][5] === null && $rows[4][6] === 4.0 && $rows[4][8] === 6.0;
    });
});

test('kardex de un servicio no exporta', function () {
    $service = Product::factory()->create(['branch_id' => $this->branch->id, 'type_product' => Product::TYPE_SERVICE]);

    test()->getJson(route('products.kardex.export', $service))->assertUnprocessable();
});

test('valorización exporta con fila de total', function () {
    test()->get(route('inventory.valuation.export', ['date' => '2026-10-31']))->assertSuccessful();

    Excel::assertDownloaded('valorizacion-2026-10-31.xlsx', function (InventoryValuationExport $export) {
        $rows = $export->array();

        return $rows[0] === ['P-01', 'Cemento', 6.0, 2.0, 12.0] && end($rows) === ['', 'Total', '', '', 12.0];
    });
});

test('costo de ventas exporta margen y totales', function () {
    test()->get(route('inventory.cost-of-sales.export', ['from' => '2026-10-01', 'to' => '2026-10-31']))->assertSuccessful();

    Excel::assertDownloaded('costo-ventas-2026-10-01-2026-10-31.xlsx', function (InventoryCostOfSalesExport $export) {
        $rows = $export->array();

        // Ventas 20, costo 8, utilidad 12, margen 60%.
        return $rows[0] === ['P-01', 'Cemento', 4.0, 20.0, 8.0, 12.0, 60.0]
            && end($rows) === ['', 'Total', '', 20.0, 8.0, 12.0, 60.0];
    });
});

test('costo de ventas sin fechas responde 422 en vez de un archivo', function () {
    test()->getJson(route('inventory.cost-of-sales.export'))->assertUnprocessable()->assertJsonValidationErrors(['from', 'to']);
});

test('compras del mes se exportan', function () {
    $shop = Shop::factory()->create(['branch_id' => $this->branch->id, 'date' => '2026-10-15', 'total' => 50]);
    Shop::factory()->create(['branch_id' => $this->branch->id, 'date' => '2026-09-15']);

    test()->get(route('shops.export', '2026-10'))->assertSuccessful();

    Excel::assertDownloaded('compras-2026-10.xlsx', function (ShopExport $export) use ($shop) {
        $rows = $export->query()->get();

        return $rows->count() === 1 && (string) $export->map($rows->first())[5] === (string) $shop->serie;
    });
});
