<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Denomination;
use App\Models\Treasury\Bank;
use App\Models\Treasury\BankAccount;
use App\Models\Treasury\CashRegister;
use App\Models\Treasury\CashRegisterSession;
use App\Models\Treasury\CashRegisterSessionDenomination;
use App\Models\User;
use App\Models\UserType;
use App\StaticClasses\CashRegisterStatuses;
use App\StaticClasses\DenominationMoments;
use App\StaticClasses\DenominationTypes;

/**
 * @return array{0: Branch, 1: User}
 */
function treasuryActingAsCompany(): array
{
    $userType = UserType::firstOrCreate(['type' => 'client']);
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();
    $user = User::factory()->create(['user_type_id' => $userType->id]);
    CompanyUser::create(['user_id' => $user->id, 'level' => 1, 'level_id' => $company->id]);

    test()->actingAs($user);

    return [$branch, $user];
}

test('bank accounts solo son visibles dentro de la sucursal de la empresa autenticada', function () {
    [$branch] = treasuryActingAsCompany();
    $otherBranch = Branch::factory()->for(Company::factory())->create();

    $bank = Bank::factory()->create(['branch_id' => $branch->id]);
    BankAccount::factory()->for($bank)->create(['branch_id' => $branch->id]);
    BankAccount::factory()->for($bank)->create(['branch_id' => $otherBranch->id]);

    expect(BankAccount::count())->toBe(1);
});

test('un banco carga sus cuentas bancarias asociadas', function () {
    [$branch] = treasuryActingAsCompany();
    $bank = Bank::factory()->create(['branch_id' => $branch->id]);
    BankAccount::factory()->for($bank)->count(2)->create(['branch_id' => $branch->id]);

    expect($bank->bankAccounts)->toHaveCount(2);
});

test('cash register expone la sesion abierta como activeSession', function () {
    [$branch, $user] = treasuryActingAsCompany();
    $cashRegister = CashRegister::factory()->create(['branch_id' => $branch->id, 'responsible_id' => $user->id]);
    $closedSession = CashRegisterSession::factory()->for($cashRegister)->create([
        'branch_id' => $branch->id,
        'opened_by' => $user->id,
        'status' => CashRegisterStatuses::CLOSED,
    ]);
    $openSession = CashRegisterSession::factory()->for($cashRegister)->create([
        'branch_id' => $branch->id,
        'opened_by' => $user->id,
        'status' => CashRegisterStatuses::OPEN,
    ]);

    expect($cashRegister->activeSession->id)->toBe($openSession->id)
        ->and($cashRegister->activeSession->id)->not->toBe($closedSession->id);
});

test('el detalle de denominaciones separa apertura y cierre', function () {
    [$branch, $user] = treasuryActingAsCompany();
    $cashRegister = CashRegister::factory()->create(['branch_id' => $branch->id, 'responsible_id' => $user->id]);
    $session = CashRegisterSession::factory()->for($cashRegister)->create([
        'branch_id' => $branch->id,
        'opened_by' => $user->id,
    ]);
    $coin = Denomination::firstOrCreate(['value' => 1, 'type' => DenominationTypes::COIN]);
    $bill = Denomination::firstOrCreate(['value' => 20, 'type' => DenominationTypes::BILL]);

    CashRegisterSessionDenomination::create([
        'cash_register_session_id' => $session->id,
        'denomination_id' => $coin->id,
        'quantity' => 10,
        'moment' => DenominationMoments::OPENING,
    ]);
    CashRegisterSessionDenomination::create([
        'cash_register_session_id' => $session->id,
        'denomination_id' => $bill->id,
        'quantity' => 3,
        'moment' => DenominationMoments::CLOSING,
    ]);

    expect($session->openingDenominations)->toHaveCount(1)
        ->and($session->openingDenominations->first()->denomination_id)->toBe($coin->id)
        ->and($session->closingDenominations)->toHaveCount(1)
        ->and($session->closingDenominations->first()->denomination_id)->toBe($bill->id);
});
