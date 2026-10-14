<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Treasury\CashRegister;
use App\Models\User;
use App\Models\UserType;
use App\Services\Treasury\CashRegisterOpeningService;
use App\StaticClasses\CashRegisterStatuses;
use App\StaticClasses\CashRegisterTypes;

/**
 * @return array{0: Branch, 1: User}
 */
function cashRegisterWithoutSessionsActingAs(): array
{
    $userType = UserType::firstOrCreate(['type' => 'client']);
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();
    $user = User::factory()->create(['user_type_id' => $userType->id]);
    CompanyUser::create(['user_id' => $user->id, 'level' => 1, 'level_id' => $company->id]);

    test()->actingAs($user);

    return [$branch, $user];
}

test('una caja sin apertura y cierre se crea abierta con su saldo inicial', function () {
    [, $user] = cashRegisterWithoutSessionsActingAs();

    $response = $this->postJson(route('cash-registers.store'), [
        'name' => 'Caja',
        'responsible_id' => $user->id,
        'type' => CashRegisterTypes::GENERAL,
        'initial_balance' => 80,
    ]);

    $response->assertCreated()
        ->assertJsonPath('uses_sessions', false)
        ->assertJsonPath('status', CashRegisterStatuses::OPEN)
        ->assertJsonPath('active_session.current_balance', 80);

    $this->getJson(route('cash-registers.index'))
        ->assertJsonPath('data.0.atts.uses_sessions', false)
        ->assertJsonPath('data.0.active_session.atts.current_balance', 80);
});

test('una caja con apertura y cierre se crea cerrada y no acepta saldo inicial', function () {
    [, $user] = cashRegisterWithoutSessionsActingAs();

    $payload = [
        'name' => 'Caja',
        'responsible_id' => $user->id,
        'type' => CashRegisterTypes::GENERAL,
        'uses_sessions' => true,
    ];

    $this->postJson(route('cash-registers.store'), [...$payload, 'initial_balance' => 10])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('initial_balance');

    $this->postJson(route('cash-registers.store'), $payload)
        ->assertCreated()
        ->assertJsonPath('uses_sessions', true)
        ->assertJsonPath('status', CashRegisterStatuses::CLOSED)
        ->assertJsonPath('active_session', null);
});

test('caja chica sin apertura toma su saldo inicial de la caja general', function () {
    [$branch, $user] = cashRegisterWithoutSessionsActingAs();

    $this->postJson(route('cash-registers.store'), [
        'name' => 'Chica',
        'responsible_id' => $user->id,
        'type' => CashRegisterTypes::PETTY,
        'initial_balance' => 50,
    ])->assertUnprocessable()->assertJsonPath('succes', false);

    expect(CashRegister::count())->toBe(0);

    $general = CashRegister::factory()->create(['branch_id' => $branch->id, 'responsible_id' => $user->id, 'type' => CashRegisterTypes::GENERAL]);
    $generalSession = app(CashRegisterOpeningService::class)->open($general, 200, $user);

    $this->postJson(route('cash-registers.store'), [
        'name' => 'Chica',
        'responsible_id' => $user->id,
        'type' => CashRegisterTypes::PETTY,
        'initial_balance' => 50,
    ])->assertCreated()->assertJsonPath('active_session.current_balance', 50);

    expect($generalSession->fresh()->current_balance)->toBe(150.0);
});

test('no se puede cerrar una caja sin apertura y cierre', function () {
    [$branch, $user] = cashRegisterWithoutSessionsActingAs();

    $general = CashRegister::factory()->create(['branch_id' => $branch->id, 'responsible_id' => $user->id, 'type' => CashRegisterTypes::GENERAL]);
    app(CashRegisterOpeningService::class)->open($general, 100, $user);

    $other = CashRegister::factory()->create(['branch_id' => $branch->id, 'responsible_id' => $user->id, 'type' => CashRegisterTypes::OTHER]);
    app(CashRegisterOpeningService::class)->open($other, 0, $user);

    $this->postJson(route('cash-registers.close', $other))
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Esta caja no usa apertura y cierre. Actívalo en la configuración de la caja.');
});

test('desactivar apertura y cierre reabre la caja cerrada en cero', function () {
    [$branch, $user] = cashRegisterWithoutSessionsActingAs();

    $other = CashRegister::factory()->withSessions()->create(['branch_id' => $branch->id, 'responsible_id' => $user->id, 'type' => CashRegisterTypes::OTHER]);

    $this->putJson(route('cash-registers.update', $other), [
        'name' => $other->name,
        'responsible_id' => $user->id,
        'uses_sessions' => false,
    ])->assertOk()
        ->assertJsonPath('uses_sessions', false)
        ->assertJsonPath('active_session.current_balance', 0);

    expect($other->sessions()->count())->toBe(1);

    $this->putJson(route('cash-registers.update', $other), [
        'name' => $other->name,
        'responsible_id' => $user->id,
        'uses_sessions' => true,
    ])->assertOk()->assertJsonPath('active_session.status', CashRegisterStatuses::OPEN);

    expect($other->sessions()->count())->toBe(1);
});

test('sin responsable la caja queda a cargo del usuario que la crea', function () {
    [, $user] = cashRegisterWithoutSessionsActingAs();

    $this->postJson(route('cash-registers.store'), [
        'name' => 'Caja',
        'type' => CashRegisterTypes::GENERAL,
        'initial_balance' => 50,
    ])->assertCreated()->assertJsonPath('responsible_id', $user->id);
});
