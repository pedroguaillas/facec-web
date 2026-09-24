<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Denomination;
use App\Models\Treasury\CashRegister;
use App\Models\User;
use App\Models\UserType;
use App\Services\Treasury\CashRegisterClosingService;
use App\Services\Treasury\CashRegisterOpeningService;
use App\StaticClasses\CashRegisterStatuses;
use App\StaticClasses\CashRegisterTypes;
use App\StaticClasses\DenominationMoments;
use App\StaticClasses\DenominationTypes;

/**
 * @return array{0: Branch, 1: User}
 */
function cashRegisterActingAsCompany(): array
{
    $userType = UserType::firstOrCreate(['type' => 'client']);
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();
    $user = User::factory()->create(['user_type_id' => $userType->id]);
    CompanyUser::create(['user_id' => $user->id, 'level' => 1, 'level_id' => $company->id]);

    test()->actingAs($user);

    return [$branch, $user];
}

test('la caja general se abre directo sin tomar saldo de otra caja', function () {
    [$branch, $user] = cashRegisterActingAsCompany();

    $general = CashRegister::factory()->create([
        'branch_id' => $branch->id,
        'responsible_id' => $user->id,
        'type' => CashRegisterTypes::GENERAL,
        'status' => CashRegisterStatuses::CLOSED,
    ]);

    $session = app(CashRegisterOpeningService::class)->open($general, 1000, $user);

    expect($session->current_balance)->toBe(1000.0)
        ->and($session->status)->toBe(CashRegisterStatuses::OPEN)
        ->and($general->fresh()->status)->toBe(CashRegisterStatuses::OPEN);
});

test('abrir caja chica toma el saldo inicial de la caja general y lo descuenta de ella', function () {
    [$branch, $user] = cashRegisterActingAsCompany();

    $general = CashRegister::factory()->create([
        'branch_id' => $branch->id,
        'responsible_id' => $user->id,
        'type' => CashRegisterTypes::GENERAL,
    ]);
    $generalSession = app(CashRegisterOpeningService::class)->open($general, 1000, $user);

    $petty = CashRegister::factory()->create([
        'branch_id' => $branch->id,
        'responsible_id' => $user->id,
        'type' => CashRegisterTypes::PETTY,
    ]);

    $pettySession = app(CashRegisterOpeningService::class)->open($petty, 150, $user);

    expect($pettySession->current_balance)->toBe(150.0)
        ->and($generalSession->fresh()->current_balance)->toBe(850.0);
});

test('abrir caja chica sin saldo suficiente en la caja general lanza excepcion', function () {
    [$branch, $user] = cashRegisterActingAsCompany();

    $general = CashRegister::factory()->create([
        'branch_id' => $branch->id,
        'responsible_id' => $user->id,
        'type' => CashRegisterTypes::GENERAL,
    ]);
    app(CashRegisterOpeningService::class)->open($general, 100, $user);

    $petty = CashRegister::factory()->create([
        'branch_id' => $branch->id,
        'responsible_id' => $user->id,
        'type' => CashRegisterTypes::PETTY,
    ]);

    expect(fn () => app(CashRegisterOpeningService::class)->open($petty, 500, $user))
        ->toThrow(RuntimeException::class);
});

test('abrir caja chica sin caja general con sesion activa lanza excepcion', function () {
    [$branch, $user] = cashRegisterActingAsCompany();

    $petty = CashRegister::factory()->create([
        'branch_id' => $branch->id,
        'responsible_id' => $user->id,
        'type' => CashRegisterTypes::PETTY,
    ]);

    expect(fn () => app(CashRegisterOpeningService::class)->open($petty, 100, $user))
        ->toThrow(RuntimeException::class);
});

test('no se puede abrir una caja que ya tiene sesion activa', function () {
    [$branch, $user] = cashRegisterActingAsCompany();

    $general = CashRegister::factory()->create([
        'branch_id' => $branch->id,
        'responsible_id' => $user->id,
        'type' => CashRegisterTypes::GENERAL,
    ]);
    app(CashRegisterOpeningService::class)->open($general, 1000, $user);

    expect(fn () => app(CashRegisterOpeningService::class)->open($general, 500, $user))
        ->toThrow(RuntimeException::class);
});

test('cerrar caja chica regresa todo el saldo a la caja general', function () {
    [$branch, $user] = cashRegisterActingAsCompany();

    $general = CashRegister::factory()->create([
        'branch_id' => $branch->id,
        'responsible_id' => $user->id,
        'type' => CashRegisterTypes::GENERAL,
    ]);
    $generalSession = app(CashRegisterOpeningService::class)->open($general, 1000, $user);

    $petty = CashRegister::factory()->create([
        'branch_id' => $branch->id,
        'responsible_id' => $user->id,
        'type' => CashRegisterTypes::PETTY,
    ]);
    app(CashRegisterOpeningService::class)->open($petty, 150, $user);

    $closedSession = app(CashRegisterClosingService::class)->close($petty, $user);

    expect($closedSession->status)->toBe(CashRegisterStatuses::CLOSED)
        ->and($closedSession->final_balance)->toBe(150.0)
        ->and($petty->fresh()->status)->toBe(CashRegisterStatuses::CLOSED)
        ->and($generalSession->fresh()->current_balance)->toBe(1000.0);
});

test('la caja general no se puede cerrar', function () {
    [$branch, $user] = cashRegisterActingAsCompany();

    $general = CashRegister::factory()->create([
        'branch_id' => $branch->id,
        'responsible_id' => $user->id,
        'type' => CashRegisterTypes::GENERAL,
    ]);
    app(CashRegisterOpeningService::class)->open($general, 1000, $user);

    expect(fn () => app(CashRegisterClosingService::class)->close($general, $user))
        ->toThrow(RuntimeException::class);
});

test('las denominaciones de apertura y cierre quedan separadas por momento', function () {
    [$branch, $user] = cashRegisterActingAsCompany();

    $general = CashRegister::factory()->create([
        'branch_id' => $branch->id,
        'responsible_id' => $user->id,
        'type' => CashRegisterTypes::GENERAL,
    ]);
    app(CashRegisterOpeningService::class)->open($general, 1000, $user);

    $petty = CashRegister::factory()->create([
        'branch_id' => $branch->id,
        'responsible_id' => $user->id,
        'type' => CashRegisterTypes::PETTY,
    ]);
    $coin = Denomination::firstOrCreate(['value' => 1, 'type' => DenominationTypes::COIN]);
    $bill = Denomination::firstOrCreate(['value' => 20, 'type' => DenominationTypes::BILL]);

    $session = app(CashRegisterOpeningService::class)->open($petty, 150, $user, [
        ['denomination_id' => $bill->id, 'quantity' => 7],
        ['denomination_id' => $coin->id, 'quantity' => 10],
    ]);

    $closed = app(CashRegisterClosingService::class)->close($petty, $user, [
        ['denomination_id' => $bill->id, 'quantity' => 6],
        ['denomination_id' => $coin->id, 'quantity' => 30],
    ]);

    expect($session->openingDenominations)->toHaveCount(2)
        ->and($session->openingDenominations->pluck('moment')->unique()->all())->toBe([DenominationMoments::OPENING])
        ->and($closed->closingDenominations)->toHaveCount(2)
        ->and($closed->closingDenominations->pluck('moment')->unique()->all())->toBe([DenominationMoments::CLOSING]);
});
