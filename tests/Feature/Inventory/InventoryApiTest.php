<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Inventory;
use App\Models\Product\Product;
use App\Models\Product\Warehouse;
use App\Models\User;
use App\Models\UserType;
use App\Services\CompanyService;
use App\Services\Product\InventoryLedgerService;
use App\Services\Product\OpeningBalanceFromStockService;
use App\StaticClasses\InventoryMovementTypes;
use Illuminate\Support\Facades\DB;

/**
 * @return array{0: Branch, 1: Company, 2: Warehouse}
 */
function inventoryApiCompany(bool $inventory = true): array
{
    $userType = UserType::firstOrCreate(['type' => 'client']);
    $company = Company::factory()->create(['inventory' => $inventory]);
    $branch = Branch::factory()->for($company)->create();
    $warehouse = Warehouse::factory()->create(['branch_id' => $branch->id, 'name' => 'Principal']);
    $user = User::factory()->create(['user_type_id' => $userType->id]);
    CompanyUser::create(['user_id' => $user->id, 'level' => 1, 'level_id' => $company->id]);
    test()->actingAs($user);

    return [$branch, $company, $warehouse];
}

function inventoryApiProduct(Branch $branch, array $attributes = []): Product
{
    return Product::factory()->create([
        'branch_id' => $branch->id, 'type_product' => Product::TYPE_PRODUCT, 'iva' => 0, 'stock' => null, ...$attributes,
    ]);
}

test('endpoints de inventario rechazan empresas sin inventario activo', function () {
    inventoryApiCompany(inventory: false);

    test()->getJson(route('inventory.stock'))
        ->assertUnprocessable()
        ->assertJsonPath('succes', false);
});

test('inventario inicial carga saldo y costo, y no se repite', function () {
    [$branch, , $warehouse] = inventoryApiCompany();
    $product = inventoryApiProduct($branch);

    $payload = [
        'warehouse_id' => $warehouse->id,
        'date' => '2026-10-01',
        'items' => [['product_id' => $product->id, 'quantity' => 20, 'unit_cost' => 1.5]],
    ];

    test()->postJson(route('inventory.opening-balance'), $payload)
        ->assertCreated()
        ->assertJsonPath('movements.0.type', InventoryMovementTypes::OPENING_BALANCE)
        ->assertJsonPath('movements.0.balance_value', 30);

    expect($product->fresh()->stock)->toBe(20.0);

    test()->postJson(route('inventory.opening-balance'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('items.0.product_id');
});

test('inventario inicial rechaza servicios', function () {
    [$branch] = inventoryApiCompany();
    $service = inventoryApiProduct($branch, ['type_product' => Product::TYPE_SERVICE]);

    test()->postJson(route('inventory.opening-balance'), [
        'date' => '2026-10-01',
        'items' => [['product_id' => $service->id, 'quantity' => 1]],
    ])->assertUnprocessable()->assertJsonValidationErrors('items.0.product_id');
});

test('ajuste de salida sin stock se rechaza y el de ingreso suma', function () {
    [$branch, , $warehouse] = inventoryApiCompany();
    $product = inventoryApiProduct($branch);

    test()->postJson(route('inventory.adjustments'), [
        'direction' => InventoryMovementTypes::OUT,
        'date' => '2026-10-01',
        'note' => 'Rotura',
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
    ])->assertUnprocessable()->assertJsonValidationErrors('products');

    test()->postJson(route('inventory.adjustments'), [
        'direction' => InventoryMovementTypes::IN,
        'date' => '2026-10-01',
        'note' => 'Conteo físico',
        'items' => [['product_id' => $product->id, 'quantity' => 4, 'unit_cost' => 2]],
    ])->assertCreated()->assertJsonPath('movements.0.type', InventoryMovementTypes::ADJUSTMENT_IN);

    $movement = Inventory::where('product_id', $product->id)->first();

    expect($movement->warehouse_id)->toBe($warehouse->id)
        ->and($movement->note)->toBe('Conteo físico')
        ->and($movement->user_id)->toBe(auth()->id())
        ->and($product->fresh()->stock)->toBe(4.0);
});

test('transferencia mueve stock entre bodegas al mismo costo', function () {
    [$branch, , $warehouse] = inventoryApiCompany();
    $other = Warehouse::factory()->create(['branch_id' => $branch->id, 'name' => 'Local 2']);
    $product = inventoryApiProduct($branch);
    app(InventoryLedgerService::class)->registerMovement($product, $warehouse, InventoryMovementTypes::PURCHASE, 10, '2026-10-01', unitCost: 3);

    $response = test()->postJson(route('inventory.transfers'), [
        'from_warehouse_id' => $warehouse->id,
        'to_warehouse_id' => $other->id,
        'date' => '2026-10-02',
        'items' => [['product_id' => $product->id, 'quantity' => 4]],
    ])->assertCreated();

    $uuid = $response->json('document_uuid');
    $ledger = app(InventoryLedgerService::class);

    expect(Inventory::where('document_uuid', $uuid)->count())->toBe(2)
        ->and($ledger->balance($product->id, $warehouse->id))->toBe(6.0)
        ->and($ledger->balance($product->id, $other->id))->toBe(4.0)
        ->and(Inventory::where('warehouse_id', $other->id)->value('average_cost'))->toBe(3.0)
        ->and($product->fresh()->stock)->toBe(10.0);
});

test('transferencia a la misma bodega se rechaza', function () {
    [$branch, , $warehouse] = inventoryApiCompany();
    $product = inventoryApiProduct($branch);

    test()->postJson(route('inventory.transfers'), [
        'from_warehouse_id' => $warehouse->id,
        'to_warehouse_id' => $warehouse->id,
        'date' => '2026-10-02',
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
    ])->assertUnprocessable()->assertJsonValidationErrors('to_warehouse_id');
});

test('bodega de otra empresa no es válida', function () {
    [$branch] = inventoryApiCompany();
    $product = inventoryApiProduct($branch);
    $foreign = Warehouse::factory()->create();

    test()->postJson(route('inventory.adjustments'), [
        'warehouse_id' => $foreign->id,
        'direction' => InventoryMovementTypes::IN,
        'date' => '2026-10-01',
        'note' => 'x',
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
    ])->assertUnprocessable()->assertJsonValidationErrors('warehouse_id');
});

test('reporte de stock detalla por bodega y filtra bajo mínimo', function () {
    [$branch, , $warehouse] = inventoryApiCompany();
    $low = inventoryApiProduct($branch, ['name' => 'A bajo', 'min_stock' => 5]);
    $ok = inventoryApiProduct($branch, ['name' => 'B ok', 'min_stock' => 1]);
    $ledger = app(InventoryLedgerService::class);
    $ledger->registerMovement($low, $warehouse, InventoryMovementTypes::PURCHASE, 2, '2026-10-01', unitCost: 1);
    $ledger->registerMovement($ok, $warehouse, InventoryMovementTypes::PURCHASE, 10, '2026-10-01', unitCost: 1);

    test()->getJson(route('inventory.stock'))
        ->assertSuccessful()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.warehouses.0.name', 'Principal')
        ->assertJsonPath('data.0.below_min', true);

    test()->getJson(route('inventory.stock', ['below_min' => 1]))
        ->assertSuccessful()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $low->id);
});

test('kardex lista los movimientos del producto en orden', function () {
    [$branch, , $warehouse] = inventoryApiCompany();
    $product = inventoryApiProduct($branch);
    $ledger = app(InventoryLedgerService::class);
    $ledger->registerMovement($product, $warehouse, InventoryMovementTypes::PURCHASE, 10, '2026-10-01', unitCost: 2);
    $ledger->registerMovement($product, $warehouse, InventoryMovementTypes::SALE, 3, '2026-10-02');

    test()->getJson(route('products.kardex', $product))
        ->assertSuccessful()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.type', InventoryMovementTypes::PURCHASE)
        ->assertJsonPath('data.1.new_balance', 7);
});

test('valorización a una fecha y costo de ventas con utilidad', function () {
    [$branch, , $warehouse] = inventoryApiCompany();
    $product = inventoryApiProduct($branch);
    $ledger = app(InventoryLedgerService::class);
    $ledger->registerMovement($product, $warehouse, InventoryMovementTypes::PURCHASE, 10, '2026-10-01', unitCost: 2);
    $ledger->registerMovement($product, $warehouse, InventoryMovementTypes::SALE, 4, '2026-10-05', price: 5);
    $ledger->registerMovement($product, $warehouse, InventoryMovementTypes::SALE_RETURN, 1, '2026-10-06', price: 5);

    test()->getJson(route('inventory.valuation', ['date' => '2026-10-02']))
        ->assertSuccessful()
        ->assertJsonPath('total_value', 20);

    test()->getJson(route('inventory.valuation', ['date' => '2026-10-31']))
        ->assertSuccessful()
        ->assertJsonPath('items.0.quantity', 7)
        ->assertJsonPath('total_value', 14);

    test()->getJson(route('inventory.cost-of-sales', ['from' => '2026-10-01', 'to' => '2026-10-31']))
        ->assertSuccessful()
        ->assertJsonPath('items.0.quantity', 3)
        ->assertJsonPath('totals.cost', 6)
        ->assertJsonPath('totals.revenue', 15)
        ->assertJsonPath('totals.gross_profit', 9);
});

test('bodega con movimientos no se puede eliminar', function () {
    [$branch, , $warehouse] = inventoryApiCompany();
    $empty = Warehouse::factory()->create(['branch_id' => $branch->id]);
    app(InventoryLedgerService::class)->registerMovement(inventoryApiProduct($branch), $warehouse, InventoryMovementTypes::PURCHASE, 1, '2026-10-01');

    test()->deleteJson(route('warehouses.destroy', $warehouse))->assertUnprocessable();
    test()->deleteJson(route('warehouses.destroy', $empty))->assertSuccessful();

    test()->postJson(route('warehouses.store'), ['name' => 'Local 3'])->assertCreated();
    expect(Warehouse::where('branch_id', $branch->id)->count())->toBe(2);
});

test('activar el inventario carga el stock de los productos como saldo inicial', function () {
    [$branch, $company] = inventoryApiCompany(inventory: false);
    $product = inventoryApiProduct($branch, ['stock' => 8]);
    inventoryApiProduct($branch, ['stock' => 3, 'type_product' => Product::TYPE_SERVICE]);

    app(CompanyService::class)->update($company, ['inventory' => true]);

    $movement = Inventory::where('product_id', $product->id)->first();

    expect(Inventory::count())->toBe(1)
        ->and($movement->type)->toBe(InventoryMovementTypes::OPENING_BALANCE)
        ->and($movement->new_balance)->toBe(8.0);

    // Idempotente.
    expect(app(OpeningBalanceFromStockService::class)->forBranch($branch))->toBe(0);
});

test('el cliente puede habilitar el stock negativo desde su configuración', function () {
    [, $company] = inventoryApiCompany();

    test()->putJson(route('settings.company.update'), ['allow_negative_stock' => true])->assertSuccessful();

    expect($company->fresh()->allow_negative_stock)->toBeTrue();
});

test('con inventario activo crear producto con stock genera saldo inicial y editarlo no cambia el stock', function () {
    [$branch, , $warehouse] = inventoryApiCompany();
    DB::table('iva_taxes')->insertOrIgnore(['code' => 0, 'percentage' => 0, 'state' => 'active']);

    $response = test()->postJson(route('products.store'), [
        'code' => 'P-001',
        'type_product' => Product::TYPE_PRODUCT,
        'name' => 'Cemento',
        'price1' => 10,
        'iva' => 0,
        'stock' => 12,
    ])->assertCreated();

    $product = Product::find($response->json('id'));
    expect(app(InventoryLedgerService::class)->balance($product->id, $warehouse->id))->toBe(12.0);

    test()->putJson(route('products.update', $product), [
        'code' => 'P-001',
        'type_product' => Product::TYPE_PRODUCT,
        'name' => 'Cemento',
        'price1' => 10,
        'iva' => 0,
        'stock' => 99,
        'min_stock' => 5,
    ])->assertSuccessful();

    expect($product->fresh()->stock)->toBe(12.0)
        ->and($product->fresh()->min_stock)->toBe(5.0);
});

test('comando registra saldos iniciales desde products.stock', function () {
    [$branch] = inventoryApiCompany();
    $product = inventoryApiProduct($branch, ['stock' => 6]);
    auth()->logout();

    test()->artisan('inventory:seed-opening-balances')->assertSuccessful();

    expect(Inventory::where('product_id', $product->id)->value('new_balance'))->toBe(6.0);
});

test('lookup de productos informa type_product', function () {
    [$branch] = inventoryApiCompany();
    $product = inventoryApiProduct($branch, ['name' => 'Cemento gris']);

    test()->getJson(route('products.lookup', ['q' => 'Cemento']))
        ->assertSuccessful()
        ->assertJsonPath('0.id', $product->id)
        ->assertJsonPath('0.type_product', Product::TYPE_PRODUCT);
});

test('editar un producto con stock negativo existente no falla, pero no acepta un negativo nuevo', function () {
    [$branch] = inventoryApiCompany(inventory: false);
    DB::table('iva_taxes')->insertOrIgnore(['code' => 0, 'percentage' => 0, 'state' => 'active']);
    $product = inventoryApiProduct($branch, ['code' => 'P-NEG', 'stock' => -3]);

    $payload = fn (float $stock) => [
        'code' => 'P-NEG', 'type_product' => Product::TYPE_PRODUCT, 'name' => 'Clavo',
        'price1' => 1, 'iva' => 0, 'stock' => $stock,
    ];

    test()->putJson(route('products.update', $product), $payload(-3))->assertSuccessful();
    test()->putJson(route('products.update', $product), $payload(-5))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('stock');
});

test('cantidades y costos del reporte de stock llegan como números', function () {
    [$branch, , $warehouse] = inventoryApiCompany();
    $product = inventoryApiProduct($branch);
    app(InventoryLedgerService::class)->registerMovement($product, $warehouse, InventoryMovementTypes::PURCHASE, 2.5, '2026-10-01', unitCost: 1.25);

    $item = test()->getJson(route('inventory.stock'))->assertSuccessful()->json('data.0');

    expect($item['stock'])->toBeFloat()
        ->and($item['average_cost'])->toBeFloat()
        ->and($item['warehouses'][0]['stock'])->toBeFloat()
        ->and($item['warehouses'][0]['average_cost'])->toBeFloat();
});

test('un servicio nunca guarda stock ni mínimo', function () {
    inventoryApiCompany(inventory: false);
    DB::table('iva_taxes')->insertOrIgnore(['code' => 0, 'percentage' => 0, 'state' => 'active']);

    $response = test()->postJson(route('products.store'), [
        'code' => 'S-001', 'type_product' => Product::TYPE_SERVICE, 'name' => 'Instalación',
        'price1' => 20, 'iva' => 0, 'stock' => 5, 'min_stock' => 1,
    ])->assertCreated();

    $service = Product::find($response->json('id'));

    expect($service->stock)->toBeNull()->and($service->min_stock)->toBeNull();
});

test('un producto con movimientos no puede convertirse en servicio', function () {
    [$branch, , $warehouse] = inventoryApiCompany();
    DB::table('iva_taxes')->insertOrIgnore(['code' => 0, 'percentage' => 0, 'state' => 'active']);
    $product = inventoryApiProduct($branch, ['code' => 'P-777']);
    app(InventoryLedgerService::class)->registerMovement($product, $warehouse, InventoryMovementTypes::PURCHASE, 1, '2026-10-01');

    test()->putJson(route('products.update', $product), [
        'code' => 'P-777', 'type_product' => Product::TYPE_SERVICE, 'name' => 'X', 'price1' => 1, 'iva' => 0,
    ])->assertUnprocessable()->assertJsonValidationErrors('type_product');

    $fresh = inventoryApiProduct($branch, ['code' => 'P-778']);
    test()->putJson(route('products.update', $fresh), [
        'code' => 'P-778', 'type_product' => Product::TYPE_SERVICE, 'name' => 'X', 'price1' => 1, 'iva' => 0,
    ])->assertSuccessful();
});

test('el ledger rechaza registrar movimientos de un servicio', function () {
    [$branch, , $warehouse] = inventoryApiCompany();
    $service = inventoryApiProduct($branch, ['type_product' => Product::TYPE_SERVICE]);

    app(InventoryLedgerService::class)->registerMovement($service, $warehouse, InventoryMovementTypes::PURCHASE, 1, '2026-10-01');
})->throws(InvalidArgumentException::class);

test('kardex de un servicio responde 422', function () {
    [$branch] = inventoryApiCompany();
    $service = inventoryApiProduct($branch, ['type_product' => Product::TYPE_SERVICE]);

    test()->getJson(route('products.kardex', $service))->assertUnprocessable()->assertJsonPath('succes', false);
});

test('valorización y costo de ventas ignoran servicios con movimientos heredados', function () {
    [$branch, , $warehouse] = inventoryApiCompany();
    $product = inventoryApiProduct($branch);
    $service = inventoryApiProduct($branch, ['type_product' => Product::TYPE_SERVICE]);
    $ledger = app(InventoryLedgerService::class);
    $ledger->registerMovement($product, $warehouse, InventoryMovementTypes::PURCHASE, 2, '2026-10-01', unitCost: 5);

    // Fila heredada de un servicio (antes del filtro por tipo).
    foreach ([[InventoryMovementTypes::PURCHASE, 'in'], [InventoryMovementTypes::SALE, 'out']] as [$type, $direction]) {
        Inventory::create([
            'product_id' => $service->id, 'warehouse_id' => $warehouse->id, 'type' => $type, 'direction' => $direction,
            'quantity' => 1, 'price' => 100, 'unit_cost' => 100, 'previous_balance' => 0, 'new_balance' => 1,
            'average_cost' => 100, 'balance_value' => 100, 'date' => '2026-10-02',
        ]);
    }

    test()->getJson(route('inventory.valuation'))
        ->assertSuccessful()
        ->assertJsonCount(1, 'items')
        ->assertJsonPath('total_value', 10);

    test()->getJson(route('inventory.cost-of-sales', ['from' => '2026-10-01', 'to' => '2026-10-31']))
        ->assertSuccessful()
        ->assertJsonCount(0, 'items');
});
