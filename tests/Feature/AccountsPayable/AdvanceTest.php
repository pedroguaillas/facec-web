<?php

use App\Models\AccountsPayable\AccountPayableMovement;
use App\Models\AccountsPayable\ProviderAdvance;
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
use App\StaticClasses\VoucherTypes;

/**
 * @return array{0: Branch, 1: Provider, 2: User, 3: CashRegisterSession}
 */
function payableAdvanceActingAsCompany(float $cajaBalance = 1000): array
{
    $userType = UserType::firstOrCreate(['type' => 'client']);
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();
    $user = User::factory()->create(['user_type_id' => $userType->id]);
    CompanyUser::create(['user_id' => $user->id, 'level' => 1, 'level_id' => $company->id]);
    test()->actingAs($user);

    $provider = Provider::factory()->create(['branch_id' => $branch->id, 'type_identification' => 'ruc']);
    $cashRegister = CashRegister::factory()->create(['branch_id' => $branch->id, 'responsible_id' => $user->id, 'status' => CashRegisterStatuses::OPEN]);
    $session = CashRegisterSession::factory()->for($cashRegister)->create(['branch_id' => $branch->id, 'opened_by' => $user->id, 'current_balance' => $cajaBalance]);

    return [$branch, $provider, $user, $session];
}

function payableAdvanceShopPayload(Branch $branch, Provider $provider, float $total, array $overrides = []): array
{
    $emisionPoint = EmisionPoint::factory()->for($branch)->create();

    return array_merge([
        'date' => now()->format('Y-m-d'),
        'voucher_type_id' => VoucherType::where('slug', VoucherTypes::COMPRA_FACTURA)->value('id'),
        'provider_id' => $provider->id,
        'point_id' => $emisionPoint->id,
        'serie' => '001-001-000123',
        'total' => $total,
        'due_date' => now()->addDays(30)->format('Y-m-d'),
        'payment_type' => PaymentTypes::ANTICIPO,
    ], $overrides);
}

test('registrar anticipo a proveedor genera el egreso en Treasury y el movimiento de cartera', function () {
    [$branch, $provider, , $session] = payableAdvanceActingAsCompany();

    $response = test()->postJson(route('providers.advances.store', $provider), [
        'amount' => 150,
        'source_type' => MovementPartyRegistry::CASH_REGISTER_SESSION,
        'source_id' => $session->id,
    ]);

    $response->assertCreated();
    expect((float) $response->json('remaining'))->toBe(150.0)
        ->and($session->fresh()->current_balance)->toBe(850.0);

    $movement = AccountPayableMovement::where('provider_id', $provider->id)->first();
    expect($movement->type)->toBe(AccountsPayableMovementTypes::ANTICIPO)
        ->and((float) $movement->debe)->toBe(150.0)
        ->and((float) $movement->new_balance)->toBe(-150.0);
});

test('compra pagada con anticipo suficiente no deja saldo pendiente', function () {
    [$branch, $provider, , $session] = payableAdvanceActingAsCompany();

    test()->postJson(route('providers.advances.store', $provider), [
        'amount' => 150,
        'source_type' => MovementPartyRegistry::CASH_REGISTER_SESSION,
        'source_id' => $session->id,
    ])->assertCreated();

    $response = test()->postJson(route('shops.store'), payableAdvanceShopPayload($branch, $provider, 100));
    $response->assertCreated();

    $shop = Shop::first();
    $advance = ProviderAdvance::where('provider_id', $provider->id)->first();

    expect((float) $shop->paid)->toBe(100.0)
        ->and((float) $advance->applied_amount)->toBe(100.0);

    $movements = AccountPayableMovement::where('provider_id', $provider->id)->orderBy('id')->get();

    expect($movements)->toHaveCount(3)
        ->and($movements[1]->type)->toBe(AccountsPayableMovementTypes::FACTURA)
        ->and($movements[2]->type)->toBe(AccountsPayableMovementTypes::APLICACION_ANTICIPO)
        ->and((float) $movements[2]->new_balance)->toBe(-50.0); // -150 anticipo +100 factura -100 aplicación
});

test('compra mayor al anticipo disponible deja el resto como crédito', function () {
    [$branch, $provider, , $session] = payableAdvanceActingAsCompany();

    test()->postJson(route('providers.advances.store', $provider), [
        'amount' => 40,
        'source_type' => MovementPartyRegistry::CASH_REGISTER_SESSION,
        'source_id' => $session->id,
    ])->assertCreated();

    $response = test()->postJson(route('shops.store'), payableAdvanceShopPayload($branch, $provider, 100));
    $response->assertCreated();

    $shop = Shop::first();
    $advance = ProviderAdvance::where('provider_id', $provider->id)->first();

    expect((float) $shop->paid)->toBe(40.0)
        ->and((float) $advance->applied_amount)->toBe(40.0);

    $movements = AccountPayableMovement::where('provider_id', $provider->id)->orderBy('id')->get();

    expect($movements)->toHaveCount(3)
        ->and((float) $movements[2]->new_balance)->toBe(60.0); // -40 anticipo +100 factura -40 aplicación = 60 pendiente
});

test('listar anticipos disponibles de un proveedor suma solo lo no consumido', function () {
    [$branch, $provider, , $session] = payableAdvanceActingAsCompany();

    test()->postJson(route('providers.advances.store', $provider), [
        'amount' => 50,
        'source_type' => MovementPartyRegistry::CASH_REGISTER_SESSION,
        'source_id' => $session->id,
    ])->assertCreated();

    test()->postJson(route('providers.advances.store', $provider), [
        'amount' => 80,
        'source_type' => MovementPartyRegistry::CASH_REGISTER_SESSION,
        'source_id' => $session->id,
    ])->assertCreated();

    // Consume el primero (50) completo y parte del segundo (30 de 80).
    test()->postJson(route('shops.store'), payableAdvanceShopPayload($branch, $provider, 80))->assertCreated();

    $response = test()->getJson(route('providers.advances.index', $provider));

    $response->assertOk();
    expect((float) $response->json('total_remaining'))->toBe(50.0)
        ->and($response->json('advances'))->toHaveCount(1)
        ->and((float) $response->json('advances.0.remaining'))->toBe(50.0);
});
