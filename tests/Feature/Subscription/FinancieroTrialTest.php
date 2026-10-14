<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Subscription;
use App\Models\Treasury\CashRegister;
use App\Models\User;
use App\Models\UserType;
use App\StaticClasses\CashRegisterTypes;
use App\StaticClasses\PlanTypes;

beforeEach(function () {
    $this->travelTo('2026-10-14 09:00');
});

function trialCompany(?string $plan = PlanTypes::FACTURADOR, string $endsAt = '2026-12-31'): Company
{
    $company = Company::factory()->withoutSubscription()->create();
    Branch::factory()->for($company)->create();

    if ($plan !== null) {
        $company->subscriptions()->create(['plan' => $plan, 'started_at' => '2026-10-01', 'ended_at' => $endsAt]);
    }

    $user = User::factory()->create([
        'user' => 'cliente_trial',
        'user_type_id' => UserType::firstOrCreate(['type' => 'client'])->id,
    ]);
    CompanyUser::create(['user_id' => $user->id, 'level' => 1, 'level_id' => $company->id]);
    test()->actingAs($user);

    return $company;
}

test('facturador activa la prueba financiero por 30 días y luego vuelve a facturador', function () {
    $company = trialCompany();

    $this->getJson(route('subscription.current'))
        ->assertOk()
        ->assertExactJson([
            'plan' => PlanTypes::FACTURADOR,
            'plan_ends_at' => '2026-12-31',
            'financiero' => false,
            'financiero_trial_available' => true,
            'needs_finance_setup' => false,
        ]);

    $this->postJson(route('subscription.financiero-trial'))
        ->assertCreated()
        ->assertExactJson([
            'succes' => true,
            'permissions' => [
                'plan' => PlanTypes::FINANCIERO,
                'plan_ends_at' => '2026-11-13',
                'financiero' => true,
                'financiero_trial_available' => false,
            ],
            'trial_ends_at' => '2026-11-13',
            'needs_finance_setup' => true,
        ]);

    expect(Subscription::where('company_id', $company->id)->where('plan', PlanTypes::FACTURADOR)->value('ended_at')->toDateString())
        ->toBe('2026-12-31');

    $this->travelTo('2026-11-14 09:00');

    $this->getJson(route('subscription.current'))
        ->assertJsonPath('plan', PlanTypes::FACTURADOR)
        ->assertJsonPath('plan_ends_at', '2026-12-31')
        ->assertJsonPath('financiero', false)
        ->assertJsonPath('financiero_trial_available', false);

    $this->postJson(route('subscription.financiero-trial'))
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Tu empresa ya usó la prueba del plan Financiero.');
});

test('si el facturador vence durante la prueba, al terminarla queda sin plan', function () {
    trialCompany(endsAt: '2026-10-20');

    $this->postJson(route('subscription.financiero-trial'))->assertCreated();

    $this->travelTo('2026-11-14 09:00');

    $this->getJson(route('subscription.current'))
        ->assertJsonPath('plan', null)
        ->assertJsonPath('financiero', false);
});

test('la prueba no se ofrece a planes con financiero ni a empresas sin plan', function (?string $plan, string $message) {
    trialCompany($plan);

    $this->getJson(route('subscription.current'))->assertJsonPath('financiero_trial_available', false);

    $this->postJson(route('subscription.financiero-trial'))
        ->assertUnprocessable()
        ->assertJsonPath('succes', false)
        ->assertJsonPath('message', $message);
})->with([
    'financiero' => [PlanTypes::FINANCIERO, 'Tu plan ya incluye las funciones del plan Financiero.'],
    'gratuito' => [PlanTypes::GRATUITO, 'Tu plan ya incluye las funciones del plan Financiero.'],
    'sin plan' => [null, 'Necesitas un plan vigente para activar la prueba del plan Financiero.'],
]);

test('needs_finance_setup se apaga al crear la caja general', function () {
    trialCompany(PlanTypes::FINANCIERO);

    $this->getJson(route('subscription.current'))->assertJsonPath('needs_finance_setup', true);

    $this->postJson(route('cash-registers.store'), [
        'name' => 'Caja general',
        'type' => CashRegisterTypes::GENERAL,
        'initial_balance' => 100,
    ])->assertCreated();

    expect(CashRegister::count())->toBe(1);
    $this->getJson(route('subscription.current'))->assertJsonPath('needs_finance_setup', false);
});

test('login expone si la prueba financiero está disponible', function () {
    trialCompany();
    auth()->forgetGuards();

    $this->postJson('/api/login', ['user' => 'cliente_trial', 'password' => 'password'])
        ->assertOk()
        ->assertJsonPath('permissions.plan', PlanTypes::FACTURADOR)
        ->assertJsonPath('permissions.financiero_trial_available', true);
});
