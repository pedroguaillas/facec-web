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
use App\StaticClasses\VoucherTypes;
use Illuminate\Support\Facades\DB;

/**
 * @return array{0: Branch, 1: Provider, 2: User}
 */
function payableActingAsCompany(): array
{
    $userType = UserType::firstOrCreate(['type' => 'client']);
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();
    $user = User::factory()->create(['user_type_id' => $userType->id]);
    CompanyUser::create(['user_id' => $user->id, 'level' => 1, 'level_id' => $company->id]);
    test()->actingAs($user);

    $provider = Provider::factory()->create(['branch_id' => $branch->id, 'type_identification' => 'ruc']);

    return [$branch, $provider, $user];
}

function payableShopPayload(Branch $branch, Provider $provider, array $overrides = []): array
{
    $emisionPoint = EmisionPoint::factory()->for($branch)->create();

    return array_merge([
        'date' => now()->format('Y-m-d'),
        'voucher_type_id' => VoucherType::where('slug', VoucherTypes::COMPRA_FACTURA)->value('id'),
        'provider_id' => $provider->id,
        'point_id' => $emisionPoint->id,
        'serie' => '001-001-000123',
        'total' => 100,
        'expiration_days' => 30,
        'payment_type' => PaymentTypes::CREDITO,
    ], $overrides);
}

test('factura de compra a crédito genera un movimiento en cuentas por pagar', function () {
    [$branch, $provider] = payableActingAsCompany();

    $response = test()->postJson(route('shops.store'), payableShopPayload($branch, $provider));

    $response->assertCreated();

    $shop = Shop::first();
    $movement = AccountPayableMovement::where('provider_id', $provider->id)->first();

    expect($movement)->not->toBeNull()
        ->and($movement->type)->toBe(AccountsPayableMovementTypes::FACTURA)
        ->and($movement->shop_id)->toBe($shop->id)
        ->and((float) $movement->haber)->toBe(100.0)
        ->and((float) $movement->previous_balance)->toBe(0.0)
        ->and((float) $movement->new_balance)->toBe(100.0)
        ->and($shop->due_date->format('Y-m-d'))->toBe(now()->addDays(30)->format('Y-m-d'));
});

test('compra de contado no genera cuenta por pagar, pero sí genera el pago en Treasury', function () {
    [$branch, $provider, $user] = payableActingAsCompany();

    $cashRegister = CashRegister::factory()->create(['branch_id' => $branch->id, 'responsible_id' => $user->id, 'status' => CashRegisterStatuses::OPEN]);
    $session = CashRegisterSession::factory()->for($cashRegister)->create(['branch_id' => $branch->id, 'opened_by' => $user->id, 'current_balance' => 500]);

    $response = test()->postJson(route('shops.store'), payableShopPayload($branch, $provider, [
        'payment_type' => PaymentTypes::CONTADO,
        'source_type' => MovementPartyRegistry::CASH_REGISTER_SESSION,
        'source_id' => $session->id,
    ]));

    $response->assertCreated();

    expect(AccountPayableMovement::where('provider_id', $provider->id)->count())->toBe(0);

    $shop = Shop::first();

    expect($shop->movement_id)->not->toBeNull()
        ->and($session->fresh()->current_balance)->toBe(400.0);
});

test('compra de contado sin saldo suficiente en caja no crea la compra', function () {
    [$branch, $provider, $user] = payableActingAsCompany();

    $cashRegister = CashRegister::factory()->create(['branch_id' => $branch->id, 'responsible_id' => $user->id, 'status' => CashRegisterStatuses::OPEN]);
    $session = CashRegisterSession::factory()->for($cashRegister)->create(['branch_id' => $branch->id, 'opened_by' => $user->id, 'current_balance' => 10]);

    $response = test()->postJson(route('shops.store'), payableShopPayload($branch, $provider, [
        'payment_type' => PaymentTypes::CONTADO,
        'source_type' => MovementPartyRegistry::CASH_REGISTER_SESSION,
        'source_id' => $session->id,
    ]));

    $response->assertStatus(422);

    expect(Shop::count())->toBe(0)
        ->and($session->fresh()->current_balance)->toBe(10.0);
});

test('compra a crédito con retención genera dos movimientos con saldo neto correcto', function () {
    [$branch, $provider] = payableActingAsCompany();

    DB::table('taxes')->insertOrIgnore([
        'code' => '303', 'conception' => 'Retención renta', 'porcentage' => 1, 'type' => 'renta',
    ]);

    $payload = payableShopPayload($branch, $provider, [
        'app_retention' => true,
        'taxes' => [[
            'code' => '1',
            'tax_code' => '303',
            'base' => 100,
            'porcentage' => 1,
            'value' => 1,
        ]],
    ]);

    $response = test()->postJson(route('shops.store'), $payload);

    $response->assertCreated();

    $shop = Shop::first();
    $movements = AccountPayableMovement::where('provider_id', $provider->id)->orderBy('id')->get();

    expect($movements)->toHaveCount(2)
        ->and($movements[0]->type)->toBe(AccountsPayableMovementTypes::FACTURA)
        ->and((float) $movements[0]->new_balance)->toBe(100.0)
        ->and($movements[1]->type)->toBe(AccountsPayableMovementTypes::RETENCION)
        ->and($movements[1]->shop_id)->toBe($shop->id)
        ->and((float) $movements[1]->debe)->toBe(1.0)
        ->and((float) $movements[1]->new_balance)->toBe(99.0);
});
