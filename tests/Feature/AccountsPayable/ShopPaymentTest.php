<?php

use App\Models\AccountsPayable\AccountPayableMovement;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\EmisionPoint;
use App\Models\Provider;
use App\Models\Shop\Shop;
use App\Models\Treasury\CashRegister;
use App\Models\Treasury\CashRegisterSession;
use App\Models\User;
use App\Models\UserType;
use App\Models\VoucherType;
use App\StaticClasses\AccountsPayableMovementTypes;
use App\StaticClasses\CashRegisterStatuses;
use App\StaticClasses\MovementPartyRegistry;
use App\StaticClasses\PaymentTypes;
use App\StaticClasses\PlanTypes;
use App\StaticClasses\VoucherTypes;

/**
 * @return array{0: Branch, 1: Shop, 2: CashRegisterSession}
 */
function creditShopReadyForPayment(float $total = 100, float $cajaBalance = 500): array
{
    $userType = UserType::firstOrCreate(['type' => 'client']);
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();
    $user = User::factory()->create(['user_type_id' => $userType->id]);
    CompanyUser::create(['user_id' => $user->id, 'level' => 1, 'level_id' => $company->id]);
    test()->actingAs($user);

    $provider = Provider::factory()->create(['branch_id' => $branch->id, 'type_identification' => 'ruc']);
    $emisionPoint = EmisionPoint::factory()->for($branch)->create();

    $response = test()->postJson(route('shops.store'), [
        'date' => now()->format('Y-m-d'),
        'voucher_type_id' => VoucherType::where('slug', VoucherTypes::COMPRA_FACTURA)->value('id'),
        'provider_id' => $provider->id,
        'point_id' => $emisionPoint->id,
        'serie' => '001-001-000123',
        'total' => $total,
        'due_date' => now()->addDays(30)->format('Y-m-d'),
        'payment_type' => PaymentTypes::CREDITO,
    ]);

    $response->assertCreated();

    $cashRegister = CashRegister::factory()->create(['branch_id' => $branch->id, 'responsible_id' => $user->id, 'status' => CashRegisterStatuses::OPEN]);
    $session = CashRegisterSession::factory()->for($cashRegister)->create(['branch_id' => $branch->id, 'opened_by' => $user->id, 'current_balance' => $cajaBalance]);

    return [$branch, Shop::first(), $session];
}

test('abono parcial a proveedor reduce el saldo pendiente y mueve caja real', function () {
    [, $shop, $session] = creditShopReadyForPayment(100);

    $response = test()->postJson(route('shops.payments.store', $shop), [
        'amount' => 40,
        'source_type' => MovementPartyRegistry::CASH_REGISTER_SESSION,
        'source_id' => $session->id,
    ]);

    $response->assertCreated();

    $payment = AccountPayableMovement::where('type', AccountsPayableMovementTypes::PAGO)->first();

    expect($payment)->not->toBeNull()
        ->and((float) $payment->debe)->toBe(40.0)
        ->and((float) $payment->previous_balance)->toBe(100.0)
        ->and((float) $payment->new_balance)->toBe(60.0)
        ->and((float) $shop->fresh()->paid)->toBe(40.0)
        ->and($session->fresh()->current_balance)->toBe(460.0);
});

test('abono mayor al saldo pendiente es rechazado', function () {
    [, $shop, $session] = creditShopReadyForPayment(100);

    $response = test()->postJson(route('shops.payments.store', $shop), [
        'amount' => 150,
        'source_type' => MovementPartyRegistry::CASH_REGISTER_SESSION,
        'source_id' => $session->id,
    ]);

    $response->assertStatus(422);
    expect((float) $shop->fresh()->paid)->toBe(0.0);
});

test('abono sin saldo suficiente en caja no aplica nada', function () {
    [, $shop, $session] = creditShopReadyForPayment(100, cajaBalance: 10);

    $response = test()->postJson(route('shops.payments.store', $shop), [
        'amount' => 40,
        'source_type' => MovementPartyRegistry::CASH_REGISTER_SESSION,
        'source_id' => $session->id,
    ]);

    $response->assertStatus(422);
    expect((float) $shop->fresh()->paid)->toBe(0.0)
        ->and($session->fresh()->current_balance)->toBe(10.0);
});

test('no se puede abonar una compra que no es a crédito', function () {
    [, $shop, $session] = creditShopReadyForPayment(100);
    $shop->update(['payment_type' => PaymentTypes::CONTADO]);

    $response = test()->postJson(route('shops.payments.store', $shop), [
        'amount' => 10,
        'source_type' => MovementPartyRegistry::CASH_REGISTER_SESSION,
        'source_id' => $session->id,
    ]);

    $response->assertStatus(422);
});

test('se puede abonar a una compra a crédito existente aunque el plan haya bajado a facturador o esté vencido', function (array $subscription) {
    [$branch, $shop, $session] = creditShopReadyForPayment(100);

    $company = $branch->company;
    $company->subscriptions()->delete();
    $company->subscriptions()->create($subscription);

    test()->postJson(route('shops.payments.store', $shop), [
        'amount' => 40,
        'source_type' => MovementPartyRegistry::CASH_REGISTER_SESSION,
        'source_id' => $session->id,
    ])->assertCreated();

    expect((float) $shop->fresh()->paid)->toBe(40.0);
})->with([
    'facturador vigente' => fn () => ['plan' => PlanTypes::FACTURADOR, 'started_at' => now()->subDay(), 'ended_at' => now()->addMonth()],
    'plan vencido' => fn () => ['plan' => PlanTypes::FINANCIERO, 'started_at' => now()->subMonths(2), 'ended_at' => now()->subDay()],
]);
