<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Treasury\Bank;
use App\Models\Treasury\BankAccount;
use App\Models\Treasury\CashRegister;
use App\Models\Treasury\CashRegisterSession;
use App\Models\User;
use App\Models\UserType;
use App\Services\Treasury\MovementService;
use App\StaticClasses\BankAccountTypes;
use App\StaticClasses\CashRegisterStatuses;
use App\StaticClasses\CashRegisterTypes;
use App\StaticClasses\MovementPartyRegistry;
use App\StaticClasses\MovementStatuses;
use App\StaticClasses\MovementTransactionTypes;
use App\StaticClasses\MovementTypes;
use App\StaticClasses\PaymentMethods;

/**
 * @return array{0: Branch, 1: User}
 */
function movementActingAsCompany(): array
{
    $userType = UserType::firstOrCreate(['type' => 'client']);
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();
    $user = User::factory()->create(['user_type_id' => $userType->id]);
    CompanyUser::create(['user_id' => $user->id, 'level' => 1, 'level_id' => $company->id]);

    test()->actingAs($user);

    return [$branch, $user];
}

test('retiro de banco a caja resta el saldo de la cuenta y suma el de la sesion de caja', function () {
    [$branch, $user] = movementActingAsCompany();

    $bank = Bank::factory()->create(['branch_id' => $branch->id]);
    $account = BankAccount::factory()->create([
        'branch_id' => $branch->id,
        'bank_id' => $bank->id,
        'type' => BankAccountTypes::CHECKING,
        'balance' => 500,
    ]);

    $cashRegister = CashRegister::factory()->create([
        'branch_id' => $branch->id,
        'responsible_id' => $user->id,
        'type' => CashRegisterTypes::GENERAL,
        'status' => CashRegisterStatuses::OPEN,
    ]);
    $session = CashRegisterSession::factory()->for($cashRegister)->create([
        'branch_id' => $branch->id,
        'opened_by' => $user->id,
        'initial_balance' => 0,
        'current_balance' => 0,
    ]);

    $movement = app(MovementService::class)->create([
        'branch_id' => $branch->id,
        'date' => now()->format('Y-m-d'),
        'type' => MovementTypes::EXPENSE,
        'transaction_type' => MovementTransactionTypes::WITHDRAWAL,
        'source_type' => MovementPartyRegistry::BANK_ACCOUNT,
        'source_id' => $account->id,
        'destination_type' => MovementPartyRegistry::CASH_REGISTER_SESSION,
        'destination_id' => $session->id,
        'amount' => 200,
        'user_id' => $user->id,
    ]);

    expect($movement->status)->toBe(MovementStatuses::COMPLETED)
        ->and($movement->previous_balance)->toBe(500.0)
        ->and($movement->new_balance)->toBe(300.0)
        ->and($account->fresh()->balance)->toBe(300.0)
        ->and($session->fresh()->current_balance)->toBe(200.0);
});

test('egreso sin saldo suficiente lanza excepcion y no modifica saldos', function () {
    [$branch, $user] = movementActingAsCompany();

    $bank = Bank::factory()->create(['branch_id' => $branch->id]);
    $account = BankAccount::factory()->create([
        'branch_id' => $branch->id,
        'bank_id' => $bank->id,
        'balance' => 50,
    ]);

    $destinationAccount = BankAccount::factory()->create([
        'branch_id' => $branch->id,
        'bank_id' => $bank->id,
        'balance' => 0,
    ]);

    expect(fn () => app(MovementService::class)->create([
        'branch_id' => $branch->id,
        'date' => now()->format('Y-m-d'),
        'type' => MovementTypes::EXPENSE,
        'transaction_type' => MovementTransactionTypes::TRANSFER,
        'source_type' => MovementPartyRegistry::BANK_ACCOUNT,
        'source_id' => $account->id,
        'destination_type' => MovementPartyRegistry::BANK_ACCOUNT,
        'destination_id' => $destinationAccount->id,
        'amount' => 200,
        'user_id' => $user->id,
    ]))->toThrow(RuntimeException::class);

    expect($account->fresh()->balance)->toBe(50.0)
        ->and($destinationAccount->fresh()->balance)->toBe(0.0);
});

test('un movimiento pendiente no toca saldos hasta completarse', function () {
    [$branch, $user] = movementActingAsCompany();

    $bank = Bank::factory()->create(['branch_id' => $branch->id]);
    $account = BankAccount::factory()->create([
        'branch_id' => $branch->id,
        'bank_id' => $bank->id,
        'balance' => 100,
    ]);

    $service = app(MovementService::class);

    $movement = $service->create([
        'branch_id' => $branch->id,
        'date' => now()->format('Y-m-d'),
        'type' => MovementTypes::INCOME,
        'transaction_type' => MovementTransactionTypes::OTHER,
        'source_type' => MovementPartyRegistry::BANK_ACCOUNT,
        'source_id' => $account->id,
        'amount' => 300,
        'status' => MovementStatuses::PENDING,
        'user_id' => $user->id,
    ]);

    expect($movement->status)->toBe(MovementStatuses::PENDING)
        ->and($account->fresh()->balance)->toBe(100.0);

    $completed = $service->complete($movement);

    expect($completed->status)->toBe(MovementStatuses::COMPLETED)
        ->and($completed->new_balance)->toBe(400.0)
        ->and($account->fresh()->balance)->toBe(400.0);
});

test('transferencia entre cuentas bancarias reduce origen e incrementa destino', function () {
    [$branch, $user] = movementActingAsCompany();

    $bank = Bank::factory()->create(['branch_id' => $branch->id]);
    $origin = BankAccount::factory()->create(['branch_id' => $branch->id, 'bank_id' => $bank->id, 'balance' => 1000]);
    $destination = BankAccount::factory()->create(['branch_id' => $branch->id, 'bank_id' => $bank->id, 'balance' => 250]);

    app(MovementService::class)->create([
        'branch_id' => $branch->id,
        'date' => now()->format('Y-m-d'),
        'type' => MovementTypes::EXPENSE,
        'transaction_type' => MovementTransactionTypes::TRANSFER,
        'source_type' => MovementPartyRegistry::BANK_ACCOUNT,
        'source_id' => $origin->id,
        'destination_type' => MovementPartyRegistry::BANK_ACCOUNT,
        'destination_id' => $destination->id,
        'amount' => 400,
        'user_id' => $user->id,
    ]);

    expect($origin->fresh()->balance)->toBe(600.0)
        ->and($destination->fresh()->balance)->toBe(650.0);
});

test('un movimiento con cheque queda pendiente aunque no se pida status explicito', function () {
    [$branch, $user] = movementActingAsCompany();

    $bank = Bank::factory()->create(['branch_id' => $branch->id]);
    $account = BankAccount::factory()->create(['branch_id' => $branch->id, 'bank_id' => $bank->id, 'balance' => 100]);

    $movement = app(MovementService::class)->create([
        'branch_id' => $branch->id,
        'date' => now()->format('Y-m-d'),
        'type' => MovementTypes::INCOME,
        'transaction_type' => MovementTransactionTypes::OTHER,
        'source_type' => MovementPartyRegistry::BANK_ACCOUNT,
        'source_id' => $account->id,
        'amount' => 300,
        'payment_method' => PaymentMethods::CHECK,
        'user_id' => $user->id,
    ]);

    expect($movement->status)->toBe(MovementStatuses::PENDING)
        ->and($account->fresh()->balance)->toBe(100.0);
});

test('un movimiento con cheque no puede forzarse a completado al crearse', function () {
    [$branch, $user] = movementActingAsCompany();

    $bank = Bank::factory()->create(['branch_id' => $branch->id]);
    $account = BankAccount::factory()->create(['branch_id' => $branch->id, 'bank_id' => $bank->id, 'balance' => 100]);

    $movement = app(MovementService::class)->create([
        'branch_id' => $branch->id,
        'date' => now()->format('Y-m-d'),
        'type' => MovementTypes::EXPENSE,
        'transaction_type' => MovementTransactionTypes::OTHER,
        'source_type' => MovementPartyRegistry::BANK_ACCOUNT,
        'source_id' => $account->id,
        'amount' => 50,
        'payment_method' => PaymentMethods::CHECK,
        'status' => MovementStatuses::COMPLETED,
        'user_id' => $user->id,
    ]);

    expect($movement->status)->toBe(MovementStatuses::PENDING)
        ->and($account->fresh()->balance)->toBe(100.0);
});

test('otros medios de pago siguen quedando completados por defecto', function () {
    [$branch, $user] = movementActingAsCompany();

    $bank = Bank::factory()->create(['branch_id' => $branch->id]);
    $account = BankAccount::factory()->create(['branch_id' => $branch->id, 'bank_id' => $bank->id, 'balance' => 100]);

    $movement = app(MovementService::class)->create([
        'branch_id' => $branch->id,
        'date' => now()->format('Y-m-d'),
        'type' => MovementTypes::INCOME,
        'transaction_type' => MovementTransactionTypes::OTHER,
        'source_type' => MovementPartyRegistry::BANK_ACCOUNT,
        'source_id' => $account->id,
        'amount' => 50,
        'payment_method' => PaymentMethods::CASH,
        'user_id' => $user->id,
    ]);

    expect($movement->status)->toBe(MovementStatuses::COMPLETED)
        ->and($account->fresh()->balance)->toBe(150.0);
});
