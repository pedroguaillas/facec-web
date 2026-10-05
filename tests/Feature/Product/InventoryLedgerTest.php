<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Customer;
use App\Models\EmisionPoint;
use App\Models\Inventory;
use App\Models\Product\Product;
use App\Models\Provider;
use App\Models\Treasury\BankAccount;
use App\Models\User;
use App\Models\UserType;
use App\Models\VoucherType;
use App\StaticClasses\InventoryMovementTypes;
use App\StaticClasses\MovementPartyRegistry;
use App\StaticClasses\PaymentTypes;
use App\StaticClasses\VoucherTypes;

/**
 * @return array{0: Branch, 1: Company}
 */
function inventoryActingAsCompany(): array
{
    $userType = UserType::firstOrCreate(['type' => 'client']);
    $company = Company::factory()->create(['inventory' => true]);
    $branch = Branch::factory()->for($company)->create();
    $user = User::factory()->create(['user_type_id' => $userType->id]);
    CompanyUser::create(['user_id' => $user->id, 'level' => 1, 'level_id' => $company->id]);
    test()->actingAs($user);

    return [$branch, $company];
}

test('compra suma al saldo del producto y venta resta, en la misma bodega', function () {
    [$branch] = inventoryActingAsCompany();

    $provider = Provider::factory()->create(['branch_id' => $branch->id, 'type_identification' => 'ruc']);
    $customer = Customer::factory()->create(['branch_id' => $branch->id]);
    $product = Product::factory()->create(['branch_id' => $branch->id, 'type_product' => Product::TYPE_PRODUCT, 'iva' => 0]);
    $emisionPoint = EmisionPoint::factory()->for($branch)->create();
    $account = BankAccount::factory()->create(['branch_id' => $branch->id, 'balance' => 1000]);

    // Compra 100 unidades.
    $shopResponse = test()->postJson(route('shops.store'), [
        'date' => now()->format('Y-m-d'),
        'voucher_type_id' => VoucherType::where('slug', VoucherTypes::COMPRA_FACTURA)->value('id'),
        'provider_id' => $provider->id,
        'point_id' => $emisionPoint->id,
        'serie' => '001-001-000123',
        'total' => 500,
        'payment_type' => PaymentTypes::CONTADO,
        'source_type' => MovementPartyRegistry::BANK_ACCOUNT,
        'source_id' => $account->id,
        'products' => [[
            'product_id' => $product->id,
            'quantity' => 100,
            'price' => 5,
            'discount' => 0,
            'iva' => 0,
        ]],
    ]);
    $shopResponse->assertCreated();

    $purchaseMovement = Inventory::where('product_id', $product->id)->where('type', InventoryMovementTypes::PURCHASE)->first();
    expect($purchaseMovement)->not->toBeNull()
        ->and((float) $purchaseMovement->previous_balance)->toBe(0.0)
        ->and((float) $purchaseMovement->new_balance)->toBe(100.0)
        ->and($purchaseMovement->warehouse_id)->not->toBeNull();

    // Vende 30 unidades.
    $orderResponse = test()->postJson(route('orders.store'), [
        'customer_id' => $customer->id,
        'total' => 150,
        'voucher_type_id' => VoucherType::where('slug', VoucherTypes::VENTA_FACTURA)->value('id'),
        'point_id' => $emisionPoint->id,
        'date' => now()->format('Y-m-d'),
        'payment_type' => PaymentTypes::CONTADO,
        'source_type' => MovementPartyRegistry::BANK_ACCOUNT,
        'source_id' => $account->id,
        'products' => [[
            'product_id' => $product->id,
            'quantity' => 30,
            'price' => 5,
            'discount' => 0,
            'iva' => 0,
        ]],
        'aditionals' => [],
    ]);
    $orderResponse->assertCreated();

    $saleMovement = Inventory::where('product_id', $product->id)->where('type', InventoryMovementTypes::SALE)->first();
    expect($saleMovement)->not->toBeNull()
        ->and((float) $saleMovement->previous_balance)->toBe(100.0)
        ->and((float) $saleMovement->new_balance)->toBe(70.0)
        ->and($saleMovement->warehouse_id)->toBe($purchaseMovement->warehouse_id); // misma bodega por defecto
});

test('linea de servicio no genera movimiento de inventario', function () {
    [$branch] = inventoryActingAsCompany();

    $customer = Customer::factory()->create(['branch_id' => $branch->id]);
    $service = Product::factory()->create(['branch_id' => $branch->id, 'type_product' => Product::TYPE_SERVICE, 'iva' => 0]);
    $emisionPoint = EmisionPoint::factory()->for($branch)->create();
    $account = BankAccount::factory()->create(['branch_id' => $branch->id, 'balance' => 0]);

    $response = test()->postJson(route('orders.store'), [
        'customer_id' => $customer->id,
        'total' => 100,
        'voucher_type_id' => VoucherType::where('slug', VoucherTypes::VENTA_FACTURA)->value('id'),
        'point_id' => $emisionPoint->id,
        'date' => now()->format('Y-m-d'),
        'payment_type' => PaymentTypes::CONTADO,
        'source_type' => MovementPartyRegistry::BANK_ACCOUNT,
        'source_id' => $account->id,
        'products' => [[
            'product_id' => $service->id,
            'quantity' => 1,
            'price' => 100,
            'discount' => 0,
            'iva' => 0,
        ]],
        'aditionals' => [],
    ]);

    $response->assertCreated();
    expect(Inventory::where('product_id', $service->id)->count())->toBe(0);
});

test('empresa sin inventario activo no genera movimientos', function () {
    $userType = UserType::firstOrCreate(['type' => 'client']);
    $company = Company::factory()->create(['inventory' => false]);
    $branch = Branch::factory()->for($company)->create();
    $user = User::factory()->create(['user_type_id' => $userType->id]);
    CompanyUser::create(['user_id' => $user->id, 'level' => 1, 'level_id' => $company->id]);
    test()->actingAs($user);

    $customer = Customer::factory()->create(['branch_id' => $branch->id]);
    $product = Product::factory()->create(['branch_id' => $branch->id, 'iva' => 0]);
    $emisionPoint = EmisionPoint::factory()->for($branch)->create();
    $account = BankAccount::factory()->create(['branch_id' => $branch->id, 'balance' => 0]);

    $response = test()->postJson(route('orders.store'), [
        'customer_id' => $customer->id,
        'total' => 100,
        'voucher_type_id' => VoucherType::where('slug', VoucherTypes::VENTA_FACTURA)->value('id'),
        'point_id' => $emisionPoint->id,
        'date' => now()->format('Y-m-d'),
        'payment_type' => PaymentTypes::CONTADO,
        'source_type' => MovementPartyRegistry::BANK_ACCOUNT,
        'source_id' => $account->id,
        'products' => [[
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 100,
            'discount' => 0,
            'iva' => 0,
        ]],
        'aditionals' => [],
    ]);

    $response->assertCreated();
    expect(Inventory::where('product_id', $product->id)->count())->toBe(0);
});
