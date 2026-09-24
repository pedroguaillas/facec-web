<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Treasury\Bank;
use App\Models\Treasury\BankAccount;
use App\Models\Treasury\CashRegister;
use App\Models\User;
use App\Models\UserType;
use App\StaticClasses\BankAccountTypes;
use App\StaticClasses\CashRegisterStatuses;
use App\StaticClasses\CashRegisterTypes;
use App\StaticClasses\MovementPartyRegistry;
use App\StaticClasses\MovementStatuses;
use App\StaticClasses\PaymentMethods;

/**
 * @return array{0: Branch, 1: User}
 */
function treasuryHttpActingAsCompany(): array
{
    $userType = UserType::firstOrCreate(['type' => 'client']);
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();
    $user = User::factory()->create(['user_type_id' => $userType->id]);
    CompanyUser::create(['user_id' => $user->id, 'level' => 1, 'level_id' => $company->id]);

    test()->actingAs($user);

    return [$branch, $user];
}

test('crea un banco y una cuenta bancaria via api', function () {
    treasuryHttpActingAsCompany();

    $bankResponse = $this->postJson(route('banks.store'), ['name' => 'Banco Pichincha']);
    $bankResponse->assertCreated()->assertJsonPath('name', 'Banco Pichincha');

    $bankId = $bankResponse->json('id');

    $accountResponse = $this->postJson(route('bank-accounts.store'), [
        'bank_id' => $bankId,
        'account_number' => '1234567890',
        'type' => BankAccountTypes::CHECKING,
    ]);

    $accountResponse->assertCreated()
        ->assertJsonPath('account_number', '1234567890')
        ->assertJsonPath('balance', 0);
});

test('no se puede eliminar un banco con cuentas registradas', function () {
    [$branch] = treasuryHttpActingAsCompany();

    $bank = Bank::factory()->create(['branch_id' => $branch->id]);
    BankAccount::factory()->for($bank)->create(['branch_id' => $branch->id]);

    $response = $this->deleteJson(route('banks.destroy', $bank));

    $response->assertStatus(422)->assertJsonPath('succes', false);
});

test('no se puede crear una segunda caja general en la misma sucursal', function () {
    [$branch, $user] = treasuryHttpActingAsCompany();

    CashRegister::factory()->create([
        'branch_id' => $branch->id,
        'responsible_id' => $user->id,
        'type' => CashRegisterTypes::GENERAL,
    ]);

    $response = $this->postJson(route('cash-registers.store'), [
        'name' => 'Caja general 2',
        'responsible_id' => $user->id,
        'type' => CashRegisterTypes::GENERAL,
    ]);

    $response->assertUnprocessable()->assertJsonValidationErrors('type');
});

test('flujo completo via api: crear caja general, abrirla, depositar a banco y cerrar caja chica', function () {
    [$branch, $user] = treasuryHttpActingAsCompany();

    $generalResponse = $this->postJson(route('cash-registers.store'), [
        'name' => 'Caja General',
        'responsible_id' => $user->id,
        'type' => CashRegisterTypes::GENERAL,
    ]);
    $generalId = $generalResponse->assertCreated()->json('id');

    $openGeneral = $this->postJson(route('cash-registers.open', $generalId), ['initial_balance' => 1000]);
    $openGeneral->assertCreated()->assertJsonPath('atts.current_balance', 1000);

    $pettyResponse = $this->postJson(route('cash-registers.store'), [
        'name' => 'Caja Chica',
        'responsible_id' => $user->id,
        'type' => CashRegisterTypes::PETTY,
    ]);
    $pettyId = $pettyResponse->assertCreated()->json('id');

    $openPetty = $this->postJson(route('cash-registers.open', $pettyId), ['initial_balance' => 100]);
    $pettySessionId = $openPetty->assertCreated()->assertJsonPath('atts.current_balance', 100)->json('id');

    $bank = Bank::factory()->create(['branch_id' => $branch->id]);
    $account = BankAccount::factory()->create([
        'branch_id' => $branch->id,
        'bank_id' => $bank->id,
        'balance' => 0,
    ]);

    $deposit = $this->postJson(route('movements.deposit'), [
        'cash_register_session_id' => $pettySessionId,
        'bank_account_id' => $account->id,
        'amount' => 40,
    ]);

    $deposit->assertCreated()
        ->assertJsonPath('atts.status', MovementStatuses::COMPLETED)
        ->assertJsonPath('source_type', MovementPartyRegistry::CASH_REGISTER_SESSION)
        ->assertJsonPath('destination_type', MovementPartyRegistry::BANK_ACCOUNT);

    expect($account->fresh()->balance)->toBe(40.0);

    $close = $this->postJson(route('cash-registers.close', $pettyId));

    $close->assertOk()
        ->assertJsonPath('atts.status', CashRegisterStatuses::CLOSED)
        ->assertJsonPath('atts.final_balance', 60);
});

test('cheque queda pendiente en el endpoint de deposito', function () {
    [$branch, $user] = treasuryHttpActingAsCompany();

    $general = CashRegister::factory()->create([
        'branch_id' => $branch->id,
        'responsible_id' => $user->id,
        'type' => CashRegisterTypes::GENERAL,
    ]);
    $sessionResponse = $this->postJson(route('cash-registers.open', $general->id), ['initial_balance' => 500]);
    $sessionId = $sessionResponse->json('id');

    $bank = Bank::factory()->create(['branch_id' => $branch->id]);
    $account = BankAccount::factory()->create(['branch_id' => $branch->id, 'bank_id' => $bank->id, 'balance' => 0]);

    $response = $this->postJson(route('movements.deposit'), [
        'cash_register_session_id' => $sessionId,
        'bank_account_id' => $account->id,
        'amount' => 100,
        'payment_method' => PaymentMethods::CHECK,
    ]);

    $response->assertCreated()->assertJsonPath('atts.status', MovementStatuses::PENDING);
    expect($account->fresh()->balance)->toBe(0.0);
});

test('el edit de caja incluye la sesion activa para saber si esta abierta', function () {
    [$branch, $user] = treasuryHttpActingAsCompany();

    $cashRegisterId = $this->postJson(route('cash-registers.store'), [
        'name' => 'Caja General',
        'responsible_id' => $user->id,
        'type' => CashRegisterTypes::GENERAL,
    ])->assertCreated()->json('id');

    $this->getJson(route('cash-registers.edit', $cashRegisterId))
        ->assertOk()
        ->assertJsonPath('active_session', null);

    $this->postJson(route('cash-registers.open', $cashRegisterId), ['initial_balance' => 300])
        ->assertCreated();

    $this->getJson(route('cash-registers.edit', $cashRegisterId))
        ->assertOk()
        ->assertJsonPath('active_session.status', CashRegisterStatuses::OPEN)
        ->assertJsonPath('active_session.current_balance', 300);
});

test('el index de bancos sigue el formato id/atts del resto de la app', function () {
    [$branch] = treasuryHttpActingAsCompany();

    Bank::factory()->create(['branch_id' => $branch->id, 'name' => 'Banco Guayaquil']);

    $response = $this->getJson(route('banks.index'));

    $response->assertOk()
        ->assertJsonPath('data.0.atts.name', 'Banco Guayaquil');
});

test('users lookup devuelve los usuarios de la empresa autenticada', function () {
    [$branch, $user] = treasuryHttpActingAsCompany();

    $response = $this->getJson(route('users.lookup'));

    $response->assertOk()->assertJsonFragment(['id' => $user->id, 'email' => $user->email]);
});

test('el filtro party_type/party_id de movimientos trae origen y destino', function () {
    [$branch, $user] = treasuryHttpActingAsCompany();

    $general = CashRegister::factory()->create([
        'branch_id' => $branch->id,
        'responsible_id' => $user->id,
        'type' => CashRegisterTypes::GENERAL,
    ]);
    $sessionId = $this->postJson(route('cash-registers.open', $general->id), ['initial_balance' => 500])
        ->assertCreated()->json('id');

    $bank = Bank::factory()->create(['branch_id' => $branch->id]);
    $account = BankAccount::factory()->create(['branch_id' => $branch->id, 'bank_id' => $bank->id, 'balance' => 1000]);

    // DEPOSITO: origen=sesión, destino=cuenta.
    $this->postJson(route('movements.deposit'), [
        'cash_register_session_id' => $sessionId,
        'bank_account_id' => $account->id,
        'amount' => 10,
    ])->assertCreated();

    // RETIRO: origen=cuenta, destino=sesión.
    $this->postJson(route('movements.withdraw'), [
        'bank_account_id' => $account->id,
        'cash_register_session_id' => $sessionId,
        'amount' => 5,
    ])->assertCreated();

    // Filtrar solo por source_type/source_id pierde el retiro (la sesión es destino ahí).
    $bySourceOnly = $this->getJson(route('movements.index', [
        'source_type' => MovementPartyRegistry::CASH_REGISTER_SESSION,
        'source_id' => $sessionId,
    ]))->assertOk()->json('data');

    expect($bySourceOnly)->toHaveCount(1);

    // party_type/party_id trae ambos, sin importar el lado.
    $byParty = $this->getJson(route('movements.index', [
        'party_type' => MovementPartyRegistry::CASH_REGISTER_SESSION,
        'party_id' => $sessionId,
    ]))->assertOk()->json('data');

    expect($byParty)->toHaveCount(2);
});
