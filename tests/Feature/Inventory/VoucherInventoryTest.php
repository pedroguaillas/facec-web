<?php

use App\Models\Inventory;
use App\Models\Order\Order;
use App\Models\Shop\Shop;
use App\Models\Shop\ShopItem;
use App\Models\VoucherType;
use App\Services\Order\OrderLifecycleService;
use App\Services\Order\OrderSriService;
use App\Services\Product\InventoryLedgerService;
use App\Services\Product\VoucherInventoryService;
use App\StaticClasses\InventoryMovementTypes;
use App\StaticClasses\MovementPartyRegistry;
use App\StaticClasses\VoucherStates;
use App\StaticClasses\VoucherTypes;

require_once __DIR__.'/InventoryTestHelpers.php';

test('compras ponderan el costo promedio y la venta sale a ese costo', function () {
    [$branch, , $warehouse] = voucherInventoryCompany();
    $product = stockProduct($branch);
    $ledger = app(InventoryLedgerService::class);

    $ledger->registerMovement($product, $warehouse, InventoryMovementTypes::PURCHASE, 10, '2026-10-01', unitCost: 2);
    $ledger->registerMovement($product, $warehouse, InventoryMovementTypes::PURCHASE, 10, '2026-10-02', unitCost: 3);
    $sale = $ledger->registerMovement($product, $warehouse, InventoryMovementTypes::SALE, 4, '2026-10-03', price: 5);

    expect($sale->direction)->toBe(InventoryMovementTypes::OUT)
        ->and($sale->unit_cost)->toBe(2.5)
        ->and($sale->average_cost)->toBe(2.5)
        ->and($sale->new_balance)->toBe(16.0)
        ->and($sale->balance_value)->toBe(40.0)
        ->and($product->fresh()->stock)->toBe(16.0);
});

test('venta sin stock suficiente se rechaza y no crea la factura', function () {
    [$branch] = voucherInventoryCompany();
    $product = stockProduct($branch);

    test()->postJson(route('orders.store'), invoicePayload($branch, $product, 3))
        ->assertUnprocessable()
        ->assertJsonPath('succes', false)
        ->assertJsonValidationErrors('products');

    expect(Order::count())->toBe(0)
        ->and(Inventory::count())->toBe(0);
});

test('empresa con stock negativo habilitado puede vender sin stock', function () {
    [$branch] = voucherInventoryCompany(['allow_negative_stock' => true]);
    $product = stockProduct($branch);

    test()->postJson(route('orders.store'), invoicePayload($branch, $product, 3))->assertCreated();

    expect(Inventory::where('product_id', $product->id)->value('new_balance'))->toBe(-3.0)
        ->and($product->fresh()->stock)->toBe(-3.0);
});

test('nota de crédito devuelve stock y proforma no mueve stock', function () {
    [$branch, , $warehouse] = voucherInventoryCompany();
    $product = stockProduct($branch);
    app(InventoryLedgerService::class)->registerMovement($product, $warehouse, InventoryMovementTypes::PURCHASE, 10, '2026-10-01', unitCost: 2);

    app(VoucherInventoryService::class)->syncOrder(orderWithItems($branch, VoucherTypes::VENTA_PROFORMA, $product, 4));
    expect($product->fresh()->stock)->toBe(10.0);

    $creditNote = orderWithItems($branch, VoucherTypes::VENTA_NOTA_CREDITO, $product, 2);
    app(VoucherInventoryService::class)->syncOrder($creditNote);

    $movement = $creditNote->inventories()->first();
    expect($movement->type)->toBe(InventoryMovementTypes::SALE_RETURN)
        ->and($movement->direction)->toBe(InventoryMovementTypes::IN)
        ->and($movement->unit_cost)->toBe(2.0)
        ->and($product->fresh()->stock)->toBe(12.0);
});

test('editar una venta revierte sus movimientos y registra los nuevos', function () {
    [$branch, , $warehouse] = voucherInventoryCompany();
    $product = stockProduct($branch);
    app(InventoryLedgerService::class)->registerMovement($product, $warehouse, InventoryMovementTypes::PURCHASE, 10, '2026-10-01', unitCost: 2);

    $order = orderWithItems($branch, VoucherTypes::VENTA_FACTURA, $product, 3);
    app(VoucherInventoryService::class)->syncOrder($order);
    expect($product->fresh()->stock)->toBe(7.0);

    test()->putJson(route('orders.update', $order), [
        'total' => 50,
        'products' => [[
            'product_id' => $product->id,
            'quantity' => 5,
            'price' => 10,
            'discount' => 0,
            'iva' => 0,
        ]],
        'aditionals' => [],
    ])->assertSuccessful();

    $original = $order->inventories()->where('type', InventoryMovementTypes::SALE)->orderBy('id')->first();

    expect($product->fresh()->stock)->toBe(5.0)
        ->and($original->reversal)->not->toBeNull()
        ->and((float) $order->inventories()->active()->sum('quantity'))->toBe(5.0);
});

test('re-sincronizar una venta sin cambios no agrega movimientos', function () {
    [$branch, , $warehouse] = voucherInventoryCompany();
    $product = stockProduct($branch);
    app(InventoryLedgerService::class)->registerMovement($product, $warehouse, InventoryMovementTypes::PURCHASE, 10, '2026-10-01', unitCost: 2);

    $order = orderWithItems($branch, VoucherTypes::VENTA_FACTURA, $product, 3);
    app(VoucherInventoryService::class)->syncOrder($order);
    app(VoucherInventoryService::class)->syncOrder($order);

    expect($order->inventories()->count())->toBe(1);
});

test('anular una venta en el SRI revierte el stock', function () {
    [$branch, , $warehouse] = voucherInventoryCompany();
    $product = stockProduct($branch);
    app(InventoryLedgerService::class)->registerMovement($product, $warehouse, InventoryMovementTypes::PURCHASE, 10, '2026-10-01', unitCost: 2);

    $order = orderWithItems($branch, VoucherTypes::VENTA_FACTURA, $product, 4);
    app(VoucherInventoryService::class)->syncOrder($order);

    test()->mock(OrderSriService::class)
        ->shouldReceive('cancel')
        ->andReturn(['status' => VoucherStates::CANCELED, 'canceled' => true]);

    app(OrderLifecycleService::class)->cancel($order);

    $reversal = $order->inventories()->where('type', InventoryMovementTypes::REVERSAL)->first();

    expect($reversal)->not->toBeNull()
        ->and($reversal->direction)->toBe(InventoryMovementTypes::IN)
        ->and($product->fresh()->stock)->toBe(10.0);
});

test('reversa de una compra no se bloquea aunque deje saldo negativo', function () {
    [$branch, , $warehouse] = voucherInventoryCompany();
    $product = stockProduct($branch);
    $ledger = app(InventoryLedgerService::class);

    $purchase = $ledger->registerMovement($product, $warehouse, InventoryMovementTypes::PURCHASE, 5, '2026-10-01', unitCost: 2);
    $ledger->registerMovement($product, $warehouse, InventoryMovementTypes::SALE, 4, '2026-10-02');
    $reversal = $ledger->reverse($purchase, '2026-10-03');

    expect($reversal->new_balance)->toBe(-4.0)
        ->and($reversal->reverses_id)->toBe($purchase->id);
});

test('el movimiento de la venta y el de una compra con el mismo id no se mezclan', function () {
    [$branch, , $warehouse] = voucherInventoryCompany();
    $product = stockProduct($branch);
    $order = orderWithItems($branch, VoucherTypes::VENTA_FACTURA, $product, 1);

    Inventory::create([
        'product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'model_type' => MovementPartyRegistry::SHOP,
        'model_id' => $order->id, 'type' => InventoryMovementTypes::PURCHASE, 'direction' => InventoryMovementTypes::IN,
        'quantity' => 1, 'date' => '2026-10-01',
    ]);

    expect($order->inventories()->count())->toBe(0);
});

test('compra entra al costo neto del descuento de la línea y nota de débito no mueve stock', function () {
    [$branch] = voucherInventoryCompany();
    $product = stockProduct($branch);

    $shop = Shop::factory()->create([
        'branch_id' => $branch->id,
        'voucher_type_id' => VoucherType::where('slug', VoucherTypes::COMPRA_FACTURA)->value('id'),
        'date' => '2026-10-01',
    ]);
    ShopItem::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'quantity' => 10, 'price' => 5, 'discount' => 10, 'iva' => 0]);
    app(VoucherInventoryService::class)->syncShop($shop);

    $debitNote = Shop::factory()->create([
        'branch_id' => $branch->id,
        'voucher_type_id' => VoucherType::where('slug', VoucherTypes::COMPRA_NOTA_DEBITO)->value('id'),
    ]);
    ShopItem::create(['shop_id' => $debitNote->id, 'product_id' => $product->id, 'quantity' => 3, 'price' => 5, 'discount' => 0, 'iva' => 0]);
    app(VoucherInventoryService::class)->syncShop($debitNote);

    $movement = $shop->inventories()->first();

    expect($movement->model_type)->toBe(MovementPartyRegistry::SHOP)
        ->and($movement->unit_cost)->toBe(4.0)
        ->and($movement->price)->toBe(5.0)
        ->and($debitNote->inventories()->count())->toBe(0)
        ->and($product->fresh()->stock)->toBe(10.0);
});

test('editar una venta antigua no intenta revertir filas históricas sin bodega', function () {
    [$branch, , $warehouse] = voucherInventoryCompany(['allow_negative_stock' => true]);
    $product = stockProduct($branch);
    $order = orderWithItems($branch, VoucherTypes::VENTA_FACTURA, $product, 2);

    // Fila de antes del kardex con bodegas (la migración le rellenó model_type).
    Inventory::create([
        'product_id' => $product->id, 'warehouse_id' => null, 'model_type' => MovementPartyRegistry::ORDER,
        'model_id' => $order->id, 'type' => InventoryMovementTypes::SALE, 'direction' => InventoryMovementTypes::OUT,
        'quantity' => 2, 'price' => 10, 'date' => '2026-01-01',
    ]);

    app(VoucherInventoryService::class)->syncOrder($order);
    app(VoucherInventoryService::class)->reverse($order);

    expect($order->inventories()->where('type', InventoryMovementTypes::REVERSAL)->count())->toBe(1)
        ->and($order->inventories()->whereNull('warehouse_id')->first()->reversal)->toBeNull()
        ->and($product->fresh()->stock)->toBe(0.0);
});
