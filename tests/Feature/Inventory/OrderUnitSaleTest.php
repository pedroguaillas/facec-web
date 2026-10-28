<?php

use App\Models\Order\Order;
use App\Models\Order\OrderItem;
use App\Services\Order\OrderSriService;
use App\StaticClasses\VoucherStates;
use App\StaticClasses\VoucherTypes;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/InventoryTestHelpers.php';

test('la venta guarda la foto de características de la unidad y baja su stock a 0', function () {
    [$branch] = voucherInventoryCompany();
    DB::table('iva_taxes')->insertOrIgnore([['code' => 0, 'percentage' => 0, 'state' => 'active']]);
    $unit = saleMotoUnit(saleMotoModel($branch), '5452', '45262');

    $orderId = $this->postJson(route('orders.store'), unitSalePayload($branch, [$unit]))->assertCreated()->json('id');

    $characteristics = [
        ['label' => 'Marca', 'value' => 'M1', 'in_sri' => true, 'sort' => 1],
        ['label' => 'Chasis', 'value' => '5452', 'in_sri' => true, 'sort' => 2],
        ['label' => 'Motor', 'value' => '45262', 'in_sri' => false, 'sort' => 3],
    ];

    expect(OrderItem::where('order_id', $orderId)->first()->details)->toEqual($characteristics)
        ->and($unit->fresh()->stock)->toBe(0.0);

    $response = $this->getJson(route('orders.edit', $orderId))
        ->assertOk()
        ->assertJsonPath('order_items.0.parent_product_id', $unit->parent_product_id);

    expect($response->json('order_items.0.details'))->toEqual($characteristics);
});

test('la foto no cambia si después se edita la unidad', function () {
    [$branch] = voucherInventoryCompany();
    $unit = saleMotoUnit(saleMotoModel($branch), '5452', '45262');
    $orderId = $this->postJson(route('orders.store'), unitSalePayload($branch, [$unit]))->assertCreated()->json('id');

    $unit->details()->whereHas('field', fn ($query) => $query->where('key', 'motor'))->update(['value' => '99999']);

    expect(collect(OrderItem::where('order_id', $orderId)->first()->details)->firstWhere('label', 'Motor')['value'])->toBe('45262');
});

test('un producto sin características no lleva foto', function () {
    [$branch] = voucherInventoryCompany(['allow_negative_stock' => true]);
    $product = stockProduct($branch);

    $orderId = $this->postJson(route('orders.store'), unitSalePayload($branch, [$product]))->assertCreated()->json('id');

    expect(OrderItem::where('order_id', $orderId)->first()->details)->toBeNull();
});

test('con inventario una unidad sin stock no se vende aunque la empresa permita stock negativo', function () {
    [$branch] = voucherInventoryCompany(['allow_negative_stock' => true]);
    $unit = saleMotoUnit(saleMotoModel($branch), '5452', '45262');
    $this->postJson(route('orders.store'), unitSalePayload($branch, [$unit]))->assertCreated();

    $this->postJson(route('orders.store'), unitSalePayload($branch, [$unit]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['products.0.product_id' => 'La unidad M5452 no está en stock.']);
});

test('una unidad se vende con cantidad 1 y sin repetirse', function () {
    [$branch] = voucherInventoryCompany();
    $unit = saleMotoUnit(saleMotoModel($branch), '5452', '45262');
    $payload = unitSalePayload($branch, [$unit, $unit]);
    $payload['products'][0]['quantity'] = 2;

    $this->postJson(route('orders.store'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'products.0.quantity' => 'Cada unidad se vende en su propia línea con cantidad 1.',
            'products.1.product_id' => 'La unidad M5452 está repetida en otra línea.',
        ]);
});

test('en factura se vende la unidad, no el modelo; la proforma sí cotiza el modelo', function () {
    [$branch] = voucherInventoryCompany();
    $model = saleMotoModel($branch);

    $this->postJson(route('orders.store'), unitSalePayload($branch, [$model]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['products.0.product_id' => 'Selecciona la unidad de MOTO M1 R1 2026 (Chasis, Motor).']);

    $this->postJson(route('orders.store'), unitSalePayload($branch, [$model], VoucherTypes::VENTA_PROFORMA))->assertCreated();
});

test('una proforma con el modelo no se convierte a factura', function () {
    [$branch] = voucherInventoryCompany();
    $model = saleMotoModel($branch);
    $proformaId = $this->postJson(route('orders.store'), unitSalePayload($branch, [$model], VoucherTypes::VENTA_PROFORMA))->json('id');

    $this->getJson(route('orders.convert-to-invoice', $proformaId))
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Selecciona la unidad de MOTO M1 R1 2026 (Chasis, Motor).');
});

test('editar la venta conserva su unidad aunque ya tenga stock 0', function () {
    [$branch] = voucherInventoryCompany();
    $unit = saleMotoUnit(saleMotoModel($branch), '5452', '45262');
    $order = Order::findOrFail($this->postJson(route('orders.store'), unitSalePayload($branch, [$unit]))->json('id'));

    $this->putJson(route('orders.update', $order), [
        'total' => 20,
        'products' => [['product_id' => $unit->id, 'quantity' => 1, 'price' => 20, 'discount' => 0, 'iva' => 0]],
        'aditionals' => [],
    ])->assertSuccessful();

    expect($unit->fresh()->stock)->toBe(0.0)
        ->and(OrderItem::where('order_id', $order->id)->first()->details)->not->toBeNull();
});

test('la nota de crédito no lleva foto', function () {
    [$branch] = voucherInventoryCompany();
    $unit = saleMotoUnit(saleMotoModel($branch), '5452', '45262');
    $this->postJson(route('orders.store'), unitSalePayload($branch, [$unit]))->assertCreated();

    $creditNoteId = $this->postJson(route('orders.store'), unitSalePayload($branch, [$unit], VoucherTypes::VENTA_NOTA_CREDITO))
        ->assertCreated()
        ->json('id');

    expect(OrderItem::where('order_id', $creditNoteId)->first()->details)->toBeNull()
        ->and($unit->fresh()->stock)->toBe(1.0);
});

test('sin inventario la unidad vendida queda en 0, no se vende dos veces y vuelve a 1 con la nota de crédito', function () {
    [$branch] = voucherInventoryCompany(['inventory' => false]);
    $unit = saleMotoUnit(saleMotoModel($branch), '5452', '45262');

    $this->postJson(route('orders.store'), unitSalePayload($branch, [$unit]))->assertCreated();

    expect($unit->fresh()->stock)->toBe(0.0);

    $this->postJson(route('orders.store'), unitSalePayload($branch, [$unit], VoucherTypes::VENTA_NOTA_VENTA))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['products.0.product_id' => 'La unidad M5452 ya se vendió.']);

    $this->postJson(route('orders.store'), unitSalePayload($branch, [$unit], VoucherTypes::VENTA_NOTA_CREDITO))->assertCreated();

    expect($unit->fresh()->stock)->toBe(1.0);

    $this->postJson(route('orders.store'), unitSalePayload($branch, [$unit]))->assertCreated();
});

test('sin inventario anular la venta devuelve la unidad a stock 1', function () {
    [$branch] = voucherInventoryCompany(['inventory' => false]);
    $unit = saleMotoUnit(saleMotoModel($branch), '5452', '45262');
    $order = Order::findOrFail($this->postJson(route('orders.store'), unitSalePayload($branch, [$unit]))->json('id'));
    $order->update(['state' => VoucherStates::AUTHORIZED]);

    $this->mock(OrderSriService::class)->shouldReceive('cancel')->andReturnUsing(function (Order $order) {
        $order->update(['state' => VoucherStates::CANCELED]);

        return ['canceled' => true, 'status' => VoucherStates::CANCELED];
    });

    $this->getJson(route('orders.cancel', $order))->assertOk();

    expect($unit->fresh()->stock)->toBe(1.0);
});
