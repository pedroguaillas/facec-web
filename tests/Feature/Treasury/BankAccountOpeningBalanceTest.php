<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Treasury\Bank;
use App\Models\Treasury\Movement;
use App\Models\User;
use App\Models\UserType;
use App\StaticClasses\BankAccountTypes;
use App\StaticClasses\MovementTransactionTypes;

test('crear cuenta bancaria con saldo inicial genera el movimiento y el balance', function () {
    $userType = UserType::firstOrCreate(['type' => 'client']);
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();
    $user = User::factory()->create(['user_type_id' => $userType->id]);
    CompanyUser::create(['user_id' => $user->id, 'level' => 1, 'level_id' => $company->id]);
    test()->actingAs($user);

    $bank = Bank::factory()->create(['branch_id' => $branch->id]);

    $response = test()->postJson(route('bank-accounts.store'), [
        'bank_id' => $bank->id,
        'account_number' => '1234567890',
        'type' => BankAccountTypes::CHECKING,
        'opening_balance' => 500,
    ]);

    $response->assertCreated();
    expect((float) $response->json('balance'))->toBe(500.0);

    $movement = Movement::where('transaction_type', MovementTransactionTypes::OPENING_BALANCE)->first();
    expect($movement)->not->toBeNull()
        ->and((float) $movement->amount)->toBe(500.0)
        ->and((float) $movement->new_balance)->toBe(500.0);
});

test('crear cuenta bancaria sin saldo inicial no genera movimiento', function () {
    $userType = UserType::firstOrCreate(['type' => 'client']);
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();
    $user = User::factory()->create(['user_type_id' => $userType->id]);
    CompanyUser::create(['user_id' => $user->id, 'level' => 1, 'level_id' => $company->id]);
    test()->actingAs($user);

    $bank = Bank::factory()->create(['branch_id' => $branch->id]);

    $response = test()->postJson(route('bank-accounts.store'), [
        'bank_id' => $bank->id,
        'account_number' => '1234567890',
        'type' => BankAccountTypes::CHECKING,
    ]);

    $response->assertCreated();
    expect((float) $response->json('balance'))->toBe(0.0)
        ->and(Movement::where('transaction_type', MovementTransactionTypes::OPENING_BALANCE)->count())->toBe(0);
});
