<?php

use App\Models\AccountsReceivable\AccountReceivableMovement;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Customer;
use App\Models\EmisionPoint;
use App\Models\Order\Order;
use App\Models\Product\Product;
use App\Models\Treasury\BankAccount;
use App\Models\User;
use App\Models\UserType;
use App\Models\VoucherType;
use App\Services\AccountsReceivable\AccountsReceivableLedgerService;
use App\StaticClasses\AccountsReceivableMovementTypes;
use App\StaticClasses\MovementPartyRegistry;
use App\StaticClasses\PaymentTypes;
use App\StaticClasses\VoucherTypes;

/**
 * @return array{0: Branch, 1: Customer}
 */
function receivableActingAsCompany(): array
{
    $userType = UserType::firstOrCreate(['type' => 'client']);
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();
    $user = User::factory()->create(['user_type_id' => $userType->id]);
    CompanyUser::create(['user_id' => $user->id, 'level' => 1, 'level_id' => $company->id]);
    test()->actingAs($user);

    $customer = Customer::factory()->create(['branch_id' => $branch->id]);

    return [$branch, $customer];
}

function receivableOrderPayload(Branch $branch, Customer $customer, array $overrides = []): array
{
    $emisionPoint = EmisionPoint::factory()->for($branch)->create();
    $product = Product::factory()->create(['branch_id' => $branch->id, 'iva' => 0]);

    return array_merge([
        'customer_id' => $customer->id,
        'total' => 100,
        'voucher_type_id' => VoucherType::where('slug', VoucherTypes::VENTA_FACTURA)->value('id'),
        'point_id' => $emisionPoint->id,
        'date' => now()->format('Y-m-d'),
        'expiration_days' => 30,
        'payment_type' => PaymentTypes::CREDITO,
        'products' => [[
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 100,
            'discount' => 0,
            'iva' => 0,
        ]],
        'aditionals' => [],
    ], $overrides);
}

test('factura de venta a crédito genera un movimiento en cuentas por cobrar', function () {
    [$branch, $customer] = receivableActingAsCompany();

    $response = test()->postJson(route('orders.store'), receivableOrderPayload($branch, $customer));

    $response->assertCreated();

    $order = Order::first();
    $movement = AccountReceivableMovement::where('customer_id', $customer->id)->first();

    expect($movement)->not->toBeNull()
        ->and($movement->type)->toBe(AccountsReceivableMovementTypes::FACTURA)
        ->and($movement->order_id)->toBe($order->id)
        ->and((float) $movement->debe)->toBe(100.0)
        ->and((float) $movement->previous_balance)->toBe(0.0)
        ->and((float) $movement->new_balance)->toBe(100.0)
        ->and($order->due_date->format('Y-m-d'))->toBe(now()->addDays(30)->format('Y-m-d'));
});

test('venta de contado no genera cuenta por cobrar, pero sí genera el cobro en Treasury', function () {
    [$branch, $customer] = receivableActingAsCompany();
    $account = BankAccount::factory()->create(['branch_id' => $branch->id, 'balance' => 0]);

    $response = test()->postJson(route('orders.store'), receivableOrderPayload($branch, $customer, [
        'payment_type' => PaymentTypes::CONTADO,
        'source_type' => MovementPartyRegistry::BANK_ACCOUNT,
        'source_id' => $account->id,
    ]));

    $response->assertCreated();

    expect(AccountReceivableMovement::where('customer_id', $customer->id)->count())->toBe(0);

    $order = Order::first();

    expect($order->movement_id)->not->toBeNull()
        ->and($account->fresh()->balance)->toBe(100.0);
});

test('nota de crédito resuelve la factura original y reduce el saldo del cliente', function () {
    [$branch, $customer] = receivableActingAsCompany();

    $facturaResponse = test()->postJson(route('orders.store'), receivableOrderPayload($branch, $customer));
    $facturaResponse->assertCreated();
    $factura = Order::first();

    $notaCredito = Order::factory()->create([
        'branch_id' => $branch->id,
        'customer_id' => $customer->id,
        'voucher_type_id' => VoucherType::where('slug', VoucherTypes::VENTA_NOTA_CREDITO)->value('id'),
        'serie_order' => $factura->serie,
        'date_order' => $factura->date,
        'total' => 40,
    ]);

    app(AccountsReceivableLedgerService::class)->registerFromOrder($notaCredito->fresh());

    $ncMovement = AccountReceivableMovement::where('customer_id', $customer->id)
        ->where('type', AccountsReceivableMovementTypes::NOTA_CREDITO)
        ->first();

    expect($ncMovement)->not->toBeNull()
        ->and($ncMovement->order_id)->toBe($factura->id)
        ->and((float) $ncMovement->haber)->toBe(40.0)
        ->and((float) $ncMovement->previous_balance)->toBe(100.0)
        ->and((float) $ncMovement->new_balance)->toBe(60.0);
});

test('nota de crédito sin factura original resolvible no genera movimiento ni rompe la creación', function () {
    [$branch, $customer] = receivableActingAsCompany();

    $notaCredito = Order::factory()->create([
        'branch_id' => $branch->id,
        'customer_id' => $customer->id,
        'voucher_type_id' => VoucherType::where('slug', VoucherTypes::VENTA_NOTA_CREDITO)->value('id'),
        'serie_order' => '001-001-000999',
        'date_order' => now()->subDay(),
        'total' => 40,
    ]);

    app(AccountsReceivableLedgerService::class)->registerFromOrder($notaCredito->fresh());

    expect(AccountReceivableMovement::where('customer_id', $customer->id)->count())->toBe(0);
});
