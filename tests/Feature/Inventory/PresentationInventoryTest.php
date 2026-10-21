<?php

use App\Models\Inventory;
use App\Models\Order\Order;
use App\Models\Order\OrderItem;
use App\Models\Product\ProductPresentation;
use App\Models\Shop\Shop;
use App\Models\Shop\ShopItem;
use App\Models\VoucherType;
use App\Services\Product\InventoryLedgerService;
use App\Services\Product\VoucherInventoryService;
use App\Services\Shop\ShopLcXmlService;
use App\StaticClasses\InventoryMovementTypes;
use App\StaticClasses\VoucherTypes;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/InventoryTestHelpers.php';

beforeEach(function () {
    [$this->branch, $this->company, $this->warehouse] = voucherInventoryCompany();
    $this->eggs = stockProduct($this->branch);
    $this->eggs->update(['name' => 'Huevos', 'code' => 'HUE', 'price1' => 0.15]);
    $this->tray = ProductPresentation::factory()->create([
        'product_id' => $this->eggs->id, 'name' => 'Cubeta x30', 'conversion_factor' => 30, 'price' => 4.5, 'code' => '7861234567890',
    ]);

    app(InventoryLedgerService::class)->registerMovement($this->eggs, $this->warehouse, InventoryMovementTypes::PURCHASE, 90, '2026-10-01', unitCost: 0.1);
});

test('vender una cubeta descuenta 30 unidades y la línea muestra la presentación', function () {
    $payload = invoicePayload($this->branch, $this->eggs, 1);
    $payload['total'] = 4.5;
    $payload['products'] = [[
        'product_id' => $this->eggs->id, 'presentation_id' => $this->tray->id, 'quantity' => 1, 'price' => 4.5, 'discount' => 0, 'iva' => 0,
    ]];

    test()->postJson(route('orders.store'), $payload)->assertCreated();

    $item = OrderItem::latest('id')->first();
    $movement = Inventory::where('type', InventoryMovementTypes::SALE)->first();

    expect($item->presentation_id)->toBe($this->tray->id)
        ->and($item->conversion_factor)->toBe(30.0)
        ->and((float) $item->quantity)->toBe(1.0)
        ->and($item->description)->toBe('Huevos (Cubeta x30)')
        ->and($item->code)->toBe('7861234567890')
        ->and($movement->quantity)->toBe(30.0)
        ->and($movement->price)->toBe(0.15)
        ->and($movement->unit_cost)->toBe(0.1)
        ->and($this->eggs->fresh()->stock)->toBe(60.0);
});

test('comprar cubetas suma unidades al costo por unidad', function () {
    $shop = Shop::factory()->create([
        'branch_id' => $this->branch->id,
        'voucher_type_id' => VoucherType::where('slug', VoucherTypes::COMPRA_FACTURA)->value('id'),
        'date' => '2026-10-02',
    ]);
    ShopItem::create([
        'shop_id' => $shop->id, 'product_id' => $this->eggs->id, 'presentation_id' => $this->tray->id, 'conversion_factor' => 30,
        'quantity' => 2, 'price' => 3.6, 'discount' => 0.6, 'iva' => 0,
    ]);

    app(VoucherInventoryService::class)->syncShop($shop);

    $movement = $shop->inventories()->first();

    // (2 × 3.60 − 0.60) / 60 = 0.11 por huevo.
    expect($movement->quantity)->toBe(60.0)
        ->and($movement->unit_cost)->toBe(0.11)
        ->and($this->eggs->fresh()->stock)->toBe(150.0);
});

test('presentación de otro producto se rechaza', function () {
    $other = stockProduct($this->branch);
    $payload = invoicePayload($this->branch, $other, 1);
    $payload['products'][0]['presentation_id'] = $this->tray->id;

    test()->postJson(route('orders.store'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('products.0.presentation_id');
});

test('editar la venta de cubeta a unidades re-sincroniza y quita la descripción de la presentación', function () {
    $order = Order::factory()->create([
        'branch_id' => $this->branch->id,
        'voucher_type_id' => VoucherType::where('slug', VoucherTypes::VENTA_FACTURA)->value('id'),
        'date' => now()->toDateString(),
        'state' => 'CREADO',
    ]);
    OrderItem::create([
        'order_id' => $order->id, 'product_id' => $this->eggs->id, 'presentation_id' => $this->tray->id, 'conversion_factor' => 30,
        'description' => 'Huevos (Cubeta x30)', 'quantity' => 1, 'price' => 4.5, 'discount' => 0, 'iva' => 0,
    ]);
    app(VoucherInventoryService::class)->syncOrder($order);
    expect($this->eggs->fresh()->stock)->toBe(60.0);

    test()->putJson(route('orders.update', $order), [
        'total' => 0.75,
        'products' => [['product_id' => $this->eggs->id, 'quantity' => 5, 'price' => 0.15, 'discount' => 0, 'iva' => 0]],
        'aditionals' => [],
    ])->assertSuccessful();

    $item = OrderItem::where('order_id', $order->id)->first();

    expect($item->presentation_id)->toBeNull()
        ->and($item->conversion_factor)->toBe(1.0)
        ->and($item->description)->toBeNull()
        ->and($this->eggs->fresh()->stock)->toBe(85.0);
});

test('cambiar el factor de la presentación no altera ventas ya registradas', function () {
    $order = Order::factory()->create([
        'branch_id' => $this->branch->id,
        'voucher_type_id' => VoucherType::where('slug', VoucherTypes::VENTA_FACTURA)->value('id'),
        'date' => now()->toDateString(),
    ]);
    OrderItem::create([
        'order_id' => $order->id, 'product_id' => $this->eggs->id, 'presentation_id' => $this->tray->id, 'conversion_factor' => 30,
        'quantity' => 1, 'price' => 4.5, 'discount' => 0, 'iva' => 0,
    ]);
    app(VoucherInventoryService::class)->syncOrder($order);

    $this->tray->update(['conversion_factor' => 24]);
    app(VoucherInventoryService::class)->syncOrder($order);

    expect($order->inventories()->count())->toBe(1)
        ->and($this->eggs->fresh()->stock)->toBe(60.0);
});

test('lookup devuelve presentaciones y encuentra por su código', function () {
    test()->getJson(route('products.lookup', ['q' => '7861234567890']))
        ->assertSuccessful()
        ->assertJsonCount(1)
        ->assertJsonPath('0.id', $this->eggs->id)
        ->assertJsonPath('0.presentations.0.name', 'Cubeta x30')
        ->assertJsonPath('0.presentations.0.conversion_factor', 30)
        ->assertJsonPath('0.presentations.0.price', 4.5);
});

test('XML de liquidación describe la línea con la presentación', function () {
    $shop = Shop::factory()->create([
        'branch_id' => $this->branch->id,
        'voucher_type_id' => VoucherType::where('slug', VoucherTypes::COMPRA_LIQUIDACION_COMPRA)->value('id'),
    ]);
    ShopItem::create([
        'shop_id' => $shop->id, 'product_id' => $this->eggs->id, 'presentation_id' => $this->tray->id, 'conversion_factor' => 30,
        'quantity' => 1, 'price' => 3, 'discount' => 0, 'iva' => 0,
    ]);

    $buildXml = new ReflectionMethod(ShopLcXmlService::class, 'buildXml');
    $xml = $buildXml->invoke(app(ShopLcXmlService::class), $this->company, $shop->id);

    expect($xml)->toContain('<codigoPrincipal>7861234567890</codigoPrincipal>')
        ->and($xml)->toContain('<descripcion>Huevos (Cubeta x30)</descripcion>');
});

test('listado de productos incluye presentaciones y busca por su código', function () {
    // El listado hace join con iva_taxes.
    DB::table('iva_taxes')->insertOrIgnore(['code' => 0, 'percentage' => 0, 'state' => 'active']);

    test()->getJson(route('products.index', ['search' => '7861234567890']))
        ->assertSuccessful()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->eggs->id)
        ->assertJsonPath('data.0.presentations.0.id', $this->tray->id)
        ->assertJsonPath('data.0.presentations.0.price', 4.5);
});

test('una presentación usada en una venta no se puede eliminar', function () {
    $unused = ProductPresentation::factory()->create(['product_id' => $this->eggs->id]);
    $order = Order::factory()->create(['branch_id' => $this->branch->id]);
    OrderItem::create([
        'order_id' => $order->id, 'product_id' => $this->eggs->id, 'presentation_id' => $this->tray->id, 'conversion_factor' => 30,
        'quantity' => 1, 'price' => 4.5, 'discount' => 0, 'iva' => 0,
    ]);

    test()->deleteJson(route('products.presentations.destroy', [$this->eggs, $this->tray]))
        ->assertUnprocessable()
        ->assertJsonPath('succes', false);

    test()->deleteJson(route('products.presentations.destroy', [$this->eggs, $unused]))->assertSuccessful();

    expect(ProductPresentation::find($this->tray->id))->not->toBeNull();
});

test('presentación inactiva se rechaza en una venta nueva y no aparece en lookup', function () {
    $this->tray->update(['active' => false]);

    $payload = invoicePayload($this->branch, $this->eggs, 1);
    $payload['products'][0]['presentation_id'] = $this->tray->id;

    test()->postJson(route('orders.store'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['products.0.presentation_id' => 'La presentación está inactiva.']);

    test()->getJson(route('products.lookup', ['q' => '7861234567890']))
        ->assertSuccessful()
        ->assertJsonCount(0);

    test()->getJson(route('products.lookup', ['q' => 'Huevos']))
        ->assertSuccessful()
        ->assertJsonCount(0, '0.presentations');
});

test('editar una venta que ya tenía una presentación ahora inactiva la conserva', function () {
    $order = Order::factory()->create([
        'branch_id' => $this->branch->id,
        'voucher_type_id' => VoucherType::where('slug', VoucherTypes::VENTA_FACTURA)->value('id'),
        'date' => now()->toDateString(),
        'state' => 'CREADO',
    ]);
    OrderItem::create([
        'order_id' => $order->id, 'product_id' => $this->eggs->id, 'presentation_id' => $this->tray->id, 'conversion_factor' => 30,
        'quantity' => 1, 'price' => 4.5, 'discount' => 0, 'iva' => 0,
    ]);
    $this->tray->update(['active' => false]);

    test()->putJson(route('orders.update', $order), [
        'total' => 9,
        'products' => [['product_id' => $this->eggs->id, 'presentation_id' => $this->tray->id, 'quantity' => 2, 'price' => 4.5, 'discount' => 0, 'iva' => 0]],
        'aditionals' => [],
    ])->assertSuccessful();

    expect(OrderItem::where('order_id', $order->id)->value('presentation_id'))->toBe($this->tray->id);
});

test('el factor de conversión guarda 6 decimales en la presentación y en la línea', function () {
    $twelfth = ProductPresentation::factory()->create([
        'product_id' => $this->eggs->id, 'name' => 'Doceava', 'conversion_factor' => 0.083333,
    ]);

    $payload = invoicePayload($this->branch, $this->eggs, 1);
    $payload['products'][0]['presentation_id'] = $twelfth->id;

    test()->postJson(route('orders.store'), $payload)->assertCreated();

    expect($twelfth->fresh()->conversion_factor)->toBe(0.083333)
        ->and(OrderItem::latest('id')->first()->conversion_factor)->toBe(0.083333);
});

test('el kardex guarda la cantidad en unidad base con 6 decimales', function () {
    $twelfth = ProductPresentation::factory()->create([
        'product_id' => $this->eggs->id, 'name' => 'Doceava', 'conversion_factor' => 0.083333,
    ]);

    $payload = invoicePayload($this->branch, $this->eggs, 3);
    $payload['products'][0]['presentation_id'] = $twelfth->id;

    test()->postJson(route('orders.store'), $payload)->assertCreated();

    expect(Inventory::where('type', InventoryMovementTypes::SALE)->value('quantity'))->toBe(0.249999)
        ->and($this->eggs->fresh()->stock)->toBe(89.750001);
});

test('vender el saldo exacto en fracciones no da stock insuficiente por ruido de float', function () {
    $ledger = app(InventoryLedgerService::class);
    $ledger->registerMovement($this->eggs, $this->warehouse, InventoryMovementTypes::SALE, 89.7, '2026-10-02');

    $ledger->registerMovement($this->eggs, $this->warehouse, InventoryMovementTypes::SALE, 0.1, '2026-10-02');
    $ledger->registerMovement($this->eggs, $this->warehouse, InventoryMovementTypes::SALE, 0.2, '2026-10-02');

    expect($this->eggs->fresh()->stock)->toBe(0.0);
});
