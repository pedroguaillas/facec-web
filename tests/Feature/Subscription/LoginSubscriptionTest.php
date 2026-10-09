<?php

use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\User;
use App\Models\UserType;
use App\StaticClasses\PlanTypes;

function loginSubscriptionUser(Company $company): User
{
    $clientType = UserType::firstOrCreate(['type' => 'client']);
    $user = User::factory()->create(['user' => 'cliente_plan', 'user_type_id' => $clientType->id]);
    CompanyUser::create(['user_id' => $user->id, 'level' => 1, 'level_id' => $company->id]);

    return $user;
}

test('login expone el plan vigente en permissions', function () {
    $company = Company::factory()->create(); // arranca con FINANCIERO activo
    loginSubscriptionUser($company);

    $this->postJson('/api/login', ['user' => 'cliente_plan', 'password' => 'password'])
        ->assertOk()
        ->assertJsonPath('permissions.plan', PlanTypes::FINANCIERO)
        ->assertJsonPath('permissions.plan_ends_at', now()->addYear()->toDateString())
        ->assertJsonPath('permissions.financiero', true);
});

test('login con plan facturador no habilita financiero', function () {
    $company = Company::factory()->withoutSubscription()->create();
    $company->subscriptions()->create(['plan' => PlanTypes::FACTURADOR, 'started_at' => now(), 'ended_at' => now()->addMonth()]);
    loginSubscriptionUser($company);

    $this->postJson('/api/login', ['user' => 'cliente_plan', 'password' => 'password'])
        ->assertOk()
        ->assertJsonPath('permissions.plan', PlanTypes::FACTURADOR)
        ->assertJsonPath('permissions.financiero', false);
});

test('login sin plan vigente devuelve plan null', function () {
    $company = Company::factory()->withoutSubscription()->create();
    loginSubscriptionUser($company);

    $this->postJson('/api/login', ['user' => 'cliente_plan', 'password' => 'password'])
        ->assertOk()
        ->assertJsonPath('permissions.plan', null)
        ->assertJsonPath('permissions.financiero', false);
});
