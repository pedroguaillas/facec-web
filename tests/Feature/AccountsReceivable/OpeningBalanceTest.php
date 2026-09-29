<?php

use App\Models\AccountsReceivable\AccountReceivableMovement;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Customer;
use App\Models\User;
use App\Models\UserType;
use App\StaticClasses\AccountsReceivableMovementTypes;

test('registrar saldo inicial de un cliente crea el movimiento sin factura', function () {
    $userType = UserType::firstOrCreate(['type' => 'client']);
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();
    $user = User::factory()->create(['user_type_id' => $userType->id]);
    CompanyUser::create(['user_id' => $user->id, 'level' => 1, 'level_id' => $company->id]);
    test()->actingAs($user);

    $customer = Customer::factory()->create(['branch_id' => $branch->id]);

    $response = test()->postJson(route('customers.account-receivable.opening-balance', $customer), [
        'amount' => 200,
    ]);

    $response->assertCreated();

    $movement = AccountReceivableMovement::where('customer_id', $customer->id)->first();

    expect($movement)->not->toBeNull()
        ->and($movement->type)->toBe(AccountsReceivableMovementTypes::SALDO_INICIAL)
        ->and($movement->order_id)->toBeNull()
        ->and((float) $movement->debe)->toBe(200.0)
        ->and((float) $movement->new_balance)->toBe(200.0);
});

test('no se puede registrar dos saldos iniciales para el mismo cliente', function () {
    $userType = UserType::firstOrCreate(['type' => 'client']);
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();
    $user = User::factory()->create(['user_type_id' => $userType->id]);
    CompanyUser::create(['user_id' => $user->id, 'level' => 1, 'level_id' => $company->id]);
    test()->actingAs($user);

    $customer = Customer::factory()->create(['branch_id' => $branch->id]);

    test()->postJson(route('customers.account-receivable.opening-balance', $customer), ['amount' => 200])->assertCreated();

    $response = test()->postJson(route('customers.account-receivable.opening-balance', $customer), ['amount' => 50]);

    $response->assertStatus(422);
    expect(AccountReceivableMovement::where('customer_id', $customer->id)->count())->toBe(1);
});
