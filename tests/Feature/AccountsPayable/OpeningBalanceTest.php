<?php

use App\Models\AccountsPayable\AccountPayableMovement;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Provider;
use App\Models\User;
use App\Models\UserType;
use App\StaticClasses\AccountsPayableMovementTypes;

test('registrar saldo inicial de un proveedor crea el movimiento sin compra', function () {
    $userType = UserType::firstOrCreate(['type' => 'client']);
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();
    $user = User::factory()->create(['user_type_id' => $userType->id]);
    CompanyUser::create(['user_id' => $user->id, 'level' => 1, 'level_id' => $company->id]);
    test()->actingAs($user);

    $provider = Provider::factory()->create(['branch_id' => $branch->id]);

    $response = test()->postJson(route('providers.account-payable.opening-balance', $provider), [
        'amount' => 150,
    ]);

    $response->assertCreated();

    $movement = AccountPayableMovement::where('provider_id', $provider->id)->first();

    expect($movement)->not->toBeNull()
        ->and($movement->type)->toBe(AccountsPayableMovementTypes::SALDO_INICIAL)
        ->and($movement->shop_id)->toBeNull()
        ->and((float) $movement->haber)->toBe(150.0)
        ->and((float) $movement->new_balance)->toBe(150.0);
});

test('no se puede registrar dos saldos iniciales para el mismo proveedor', function () {
    $userType = UserType::firstOrCreate(['type' => 'client']);
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();
    $user = User::factory()->create(['user_type_id' => $userType->id]);
    CompanyUser::create(['user_id' => $user->id, 'level' => 1, 'level_id' => $company->id]);
    test()->actingAs($user);

    $provider = Provider::factory()->create(['branch_id' => $branch->id]);

    test()->postJson(route('providers.account-payable.opening-balance', $provider), ['amount' => 150])->assertCreated();

    $response = test()->postJson(route('providers.account-payable.opening-balance', $provider), ['amount' => 30]);

    $response->assertStatus(422);
    expect(AccountPayableMovement::where('provider_id', $provider->id)->count())->toBe(1);
});
