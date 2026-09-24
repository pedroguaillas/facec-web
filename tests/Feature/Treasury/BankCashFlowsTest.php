<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Treasury\Bank;
use App\Models\Treasury\BankAccount;
use App\Models\Treasury\CashRegister;
use App\Models\User;
use App\Models\UserType;
use App\Services\Treasury\BankTransferService;
use App\Services\Treasury\CashRegisterOpeningService;
use App\Services\Treasury\DepositService;
use App\Services\Treasury\WithdrawalService;
use App\StaticClasses\CashRegisterTypes;

/**
 * @return array{0: Branch, 1: User}
 */
function bankCashFlowActingAsCompany(): array
{
    $userType = UserType::firstOrCreate(['type' => 'client']);
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();
    $user = User::factory()->create(['user_type_id' => $userType->id]);
    CompanyUser::create(['user_id' => $user->id, 'level' => 1, 'level_id' => $company->id]);

    test()->actingAs($user);

    return [$branch, $user];
}

test('deposito mueve saldo de la sesion de caja a la cuenta bancaria', function () {
    [$branch, $user] = bankCashFlowActingAsCompany();

    $general = CashRegister::factory()->create([
        'branch_id' => $branch->id,
        'responsible_id' => $user->id,
        'type' => CashRegisterTypes::GENERAL,
    ]);
    $session = app(CashRegisterOpeningService::class)->open($general, 500, $user);

    $bank = Bank::factory()->create(['branch_id' => $branch->id]);
    $account = BankAccount::factory()->create(['branch_id' => $branch->id, 'bank_id' => $bank->id, 'balance' => 0]);

    app(DepositService::class)->deposit($session, $account, 200, $user);

    expect($session->fresh()->current_balance)->toBe(300.0)
        ->and($account->fresh()->balance)->toBe(200.0);
});

test('retiro mueve saldo de la cuenta bancaria a la sesion de caja', function () {
    [$branch, $user] = bankCashFlowActingAsCompany();

    $general = CashRegister::factory()->create([
        'branch_id' => $branch->id,
        'responsible_id' => $user->id,
        'type' => CashRegisterTypes::GENERAL,
    ]);
    $session = app(CashRegisterOpeningService::class)->open($general, 0, $user);

    $bank = Bank::factory()->create(['branch_id' => $branch->id]);
    $account = BankAccount::factory()->create(['branch_id' => $branch->id, 'bank_id' => $bank->id, 'balance' => 1000]);

    app(WithdrawalService::class)->withdraw($account, $session, 400, $user);

    expect($account->fresh()->balance)->toBe(600.0)
        ->and($session->fresh()->current_balance)->toBe(400.0);
});

test('transferencia a la misma cuenta lanza excepcion', function () {
    [$branch, $user] = bankCashFlowActingAsCompany();

    $bank = Bank::factory()->create(['branch_id' => $branch->id]);
    $account = BankAccount::factory()->create(['branch_id' => $branch->id, 'bank_id' => $bank->id, 'balance' => 100]);

    expect(fn () => app(BankTransferService::class)->transfer($account, $account, 50, $user))
        ->toThrow(RuntimeException::class);
});
