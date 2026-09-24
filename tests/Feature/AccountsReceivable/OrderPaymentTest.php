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
use App\StaticClasses\AccountsReceivableMovementTypes;
use App\StaticClasses\MovementPartyRegistry;
use App\StaticClasses\PaymentTypes;
use App\StaticClasses\VoucherTypes;

/**
 * @return array{0: Branch, 1: Order, 2: BankAccount}
 */
function creditOrderReadyForPayment(float $total = 100): array
{
    $userType = UserType::firstOrCreate(['type' => 'client']);
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();
    $user = User::factory()->create(['user_type_id' => $userType->id]);
    CompanyUser::create(['user_id' => $user->id, 'level' => 1, 'level_id' => $company->id]);
    test()->actingAs($user);

    $customer = Customer::factory()->create(['branch_id' => $branch->id]);
    $emisionPoint = EmisionPoint::factory()->for($branch)->create();
    $product = Product::factory()->create(['branch_id' => $branch->id, 'iva' => 0]);

    $response = test()->postJson(route('orders.store'), [
        'customer_id' => $customer->id,
        'total' => $total,
        'voucher_type_id' => VoucherType::where('slug', VoucherTypes::VENTA_FACTURA)->value('id'),
        'point_id' => $emisionPoint->id,
        'date' => now()->format('Y-m-d'),
        'expiration_days' => 30,
        'payment_type' => PaymentTypes::CREDITO,
        'products' => [[
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => $total,
            'discount' => 0,
            'iva' => 0,
        ]],
        'aditionals' => [],
    ]);

    $response->assertCreated();

    $account = BankAccount::factory()->create(['branch_id' => $branch->id, 'balance' => 0]);

    return [$branch, Order::first(), $account];
}

test('abono parcial reduce el saldo pendiente y mueve caja/banco real', function () {
    [, $order, $account] = creditOrderReadyForPayment(100);

    $response = test()->postJson(route('orders.payments.store', $order), [
        'amount' => 40,
        'source_type' => MovementPartyRegistry::BANK_ACCOUNT,
        'source_id' => $account->id,
    ]);

    $response->assertCreated();

    $payment = AccountReceivableMovement::where('type', AccountsReceivableMovementTypes::PAGO)->first();

    expect($payment)->not->toBeNull()
        ->and((float) $payment->haber)->toBe(40.0)
        ->and((float) $payment->previous_balance)->toBe(100.0)
        ->and((float) $payment->new_balance)->toBe(60.0)
        ->and((float) $order->fresh()->paid)->toBe(40.0)
        ->and($account->fresh()->balance)->toBe(40.0);
});

test('abono mayor al saldo pendiente es rechazado', function () {
    [, $order, $account] = creditOrderReadyForPayment(100);

    $response = test()->postJson(route('orders.payments.store', $order), [
        'amount' => 150,
        'source_type' => MovementPartyRegistry::BANK_ACCOUNT,
        'source_id' => $account->id,
    ]);

    $response->assertStatus(422);
    expect((float) $order->fresh()->paid)->toBe(0.0);
});

test('no se puede abonar una venta que no es a crédito', function () {
    [$branch, $order, $account] = creditOrderReadyForPayment(100);
    $order->update(['payment_type' => PaymentTypes::CONTADO]);

    $response = test()->postJson(route('orders.payments.store', $order), [
        'amount' => 10,
        'source_type' => MovementPartyRegistry::BANK_ACCOUNT,
        'source_id' => $account->id,
    ]);

    $response->assertStatus(422);
});
