<?php

use App\Models\AccountsReceivable\AccountReceivableMovement;
use App\Models\AccountsReceivable\CustomerAdvance;
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
use App\StaticClasses\AccountsReceivableMovementTypes;
use App\StaticClasses\MovementPartyRegistry;
use App\StaticClasses\PaymentTypes;
use App\StaticClasses\VoucherTypes;

/**
 * @return array{0: Branch, 1: Customer, 2: BankAccount}
 */
function advanceActingAsCompany(): array
{
    $userType = UserType::firstOrCreate(['type' => 'client']);
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();
    $user = User::factory()->create(['user_type_id' => $userType->id]);
    CompanyUser::create(['user_id' => $user->id, 'level' => 1, 'level_id' => $company->id]);
    test()->actingAs($user);

    $customer = Customer::factory()->create(['branch_id' => $branch->id]);
    $account = BankAccount::factory()->create(['branch_id' => $branch->id, 'balance' => 0]);

    return [$branch, $customer, $account];
}

function advanceOrderPayload(Branch $branch, Customer $customer, float $total, array $overrides = []): array
{
    $emisionPoint = EmisionPoint::factory()->for($branch)->create();
    $product = Product::factory()->create(['branch_id' => $branch->id, 'iva' => 0]);

    return array_merge([
        'customer_id' => $customer->id,
        'total' => $total,
        'voucher_type_id' => VoucherType::where('slug', VoucherTypes::VENTA_FACTURA)->value('id'),
        'point_id' => $emisionPoint->id,
        'date' => now()->format('Y-m-d'),
        'due_date' => now()->addDays(30)->format('Y-m-d'),
        'payment_type' => PaymentTypes::ANTICIPO,
        'products' => [[
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => $total,
            'discount' => 0,
            'iva' => 0,
        ]],
        'aditionals' => [],
    ], $overrides);
}

test('registrar anticipo de cliente genera el ingreso en Treasury y el movimiento de cartera', function () {
    [$branch, $customer, $account] = advanceActingAsCompany();

    $response = test()->postJson(route('customers.advances.store', $customer), [
        'amount' => 200,
        'source_type' => MovementPartyRegistry::BANK_ACCOUNT,
        'source_id' => $account->id,
    ]);

    $response->assertCreated();
    expect((float) $response->json('remaining'))->toBe(200.0)
        ->and($account->fresh()->balance)->toBe(200.0);

    $movement = AccountReceivableMovement::where('customer_id', $customer->id)->first();
    expect($movement->type)->toBe(AccountsReceivableMovementTypes::ANTICIPO)
        ->and((float) $movement->haber)->toBe(200.0)
        ->and((float) $movement->new_balance)->toBe(-200.0);
});

test('factura pagada con anticipo suficiente no deja saldo pendiente', function () {
    [$branch, $customer, $account] = advanceActingAsCompany();

    test()->postJson(route('customers.advances.store', $customer), [
        'amount' => 200,
        'source_type' => MovementPartyRegistry::BANK_ACCOUNT,
        'source_id' => $account->id,
    ])->assertCreated();

    $response = test()->postJson(route('orders.store'), advanceOrderPayload($branch, $customer, 100));
    $response->assertCreated();

    $order = Order::first();
    $advance = CustomerAdvance::where('customer_id', $customer->id)->first();

    expect((float) $order->paid)->toBe(100.0)
        ->and((float) $advance->applied_amount)->toBe(100.0);

    $movements = AccountReceivableMovement::where('customer_id', $customer->id)->orderBy('id')->get();

    expect($movements)->toHaveCount(3) // ANTICIPO, FACTURA, APLICACION_ANTICIPO
        ->and($movements[1]->type)->toBe(AccountsReceivableMovementTypes::FACTURA)
        ->and((float) $movements[1]->debe)->toBe(100.0)
        ->and($movements[2]->type)->toBe(AccountsReceivableMovementTypes::APLICACION_ANTICIPO)
        ->and((float) $movements[2]->haber)->toBe(100.0)
        ->and((float) $movements[2]->new_balance)->toBe(-100.0); // -200 anticipo +100 factura -100 aplicación
});

test('factura mayor al anticipo disponible deja el resto como crédito', function () {
    [$branch, $customer, $account] = advanceActingAsCompany();

    test()->postJson(route('customers.advances.store', $customer), [
        'amount' => 60,
        'source_type' => MovementPartyRegistry::BANK_ACCOUNT,
        'source_id' => $account->id,
    ])->assertCreated();

    $response = test()->postJson(route('orders.store'), advanceOrderPayload($branch, $customer, 100));
    $response->assertCreated();

    $order = Order::first();
    $advance = CustomerAdvance::where('customer_id', $customer->id)->first();

    expect((float) $order->paid)->toBe(60.0)
        ->and((float) $advance->applied_amount)->toBe(60.0);

    $movements = AccountReceivableMovement::where('customer_id', $customer->id)->orderBy('id')->get();

    expect($movements)->toHaveCount(3)
        ->and((float) $movements[2]->new_balance)->toBe(40.0); // -60 anticipo +100 factura -60 aplicación = 40 pendiente
});

test('listar anticipos disponibles de un cliente suma solo lo no consumido', function () {
    [$branch, $customer, $account] = advanceActingAsCompany();

    test()->postJson(route('customers.advances.store', $customer), [
        'amount' => 50,
        'source_type' => MovementPartyRegistry::BANK_ACCOUNT,
        'source_id' => $account->id,
    ])->assertCreated();

    test()->postJson(route('customers.advances.store', $customer), [
        'amount' => 80,
        'source_type' => MovementPartyRegistry::BANK_ACCOUNT,
        'source_id' => $account->id,
    ])->assertCreated();

    // Consume el primero (50) completo y parte del segundo (30 de 80).
    test()->postJson(route('orders.store'), advanceOrderPayload($branch, $customer, 80))->assertCreated();

    $response = test()->getJson(route('customers.advances.index', $customer));

    $response->assertOk();
    expect((float) $response->json('total_remaining'))->toBe(50.0)
        ->and($response->json('advances'))->toHaveCount(1)
        ->and((float) $response->json('advances.0.remaining'))->toBe(50.0);
});
