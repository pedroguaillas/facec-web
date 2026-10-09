<?php

use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserType;
use App\StaticClasses\PlanTypes;
use Illuminate\Support\Facades\DB;

function subscriptionActingAsAdmin(): User
{
    $adminType = UserType::firstOrCreate(['type' => 'admin']);
    $admin = User::factory()->create(['user_type_id' => $adminType->id]);

    test()->actingAs($admin);

    return $admin;
}

test('asignar un plan nuevo cierra el anterior y crea uno nuevo', function () {
    subscriptionActingAsAdmin();
    $company = Company::factory()->create(); // arranca con FINANCIERO activo

    $response = test()->postJson(route('admin.companies.subscription', $company), [
        'plan' => PlanTypes::FACTURADOR,
        'ends_at' => now()->addYear()->format('Y-m-d'),
        'amount' => 50,
    ]);

    $response->assertCreated();

    $company->refresh();
    $active = $company->currentSubscription();

    expect($active->plan)->toBe(PlanTypes::FACTURADOR)
        ->and(Subscription::where('company_id', $company->id)->where('plan', PlanTypes::FINANCIERO)->whereNotNull('ended_at')->exists())->toBeTrue()
        ->and(Subscription::where('company_id', $company->id)->count())->toBe(2);
});

test('asignar plan gratuito con ends_at crea la suscripción con vencimiento', function () {
    subscriptionActingAsAdmin();
    $company = Company::factory()->withoutSubscription()->create();

    $response = test()->postJson(route('admin.companies.subscription', $company), [
        'plan' => PlanTypes::GRATUITO,
        'ends_at' => now()->addMonth()->format('Y-m-d'),
    ]);

    $response->assertCreated();
    expect($company->currentSubscription()->plan)->toBe(PlanTypes::GRATUITO);
});

test('admin ve el plan vigente y el historial de suscripciones de una empresa', function () {
    subscriptionActingAsAdmin();
    $company = Company::factory()->create(); // arranca con FINANCIERO activo

    test()->postJson(route('admin.companies.subscription', $company), [
        'plan' => PlanTypes::FACTURADOR,
        'ends_at' => now()->addYear()->format('Y-m-d'),
        'amount' => 50,
    ])->assertCreated();

    $response = test()->getJson(route('admin.companies.subscriptions', $company));

    $response->assertOk()
        ->assertJsonPath('company.id', $company->id)
        ->assertJsonPath('company.ruc', $company->ruc)
        ->assertJsonPath('current.plan', PlanTypes::FACTURADOR)
        ->assertJsonPath('current.amount', 50)
        ->assertJsonCount(2, 'history')
        ->assertJsonPath('history.0.plan', PlanTypes::FACTURADOR)
        ->assertJsonPath('history.1.plan', PlanTypes::FINANCIERO);
});

test('un cliente no puede ver el historial de suscripciones', function () {
    $clientType = UserType::firstOrCreate(['type' => 'client']);
    test()->actingAs(User::factory()->create(['user_type_id' => $clientType->id]));
    $company = Company::factory()->create();

    test()->getJson(route('admin.companies.subscriptions', $company))
        ->assertForbidden()
        ->assertJsonPath('succes', false);
});

test('asignar un plan pago sin fecha de fin es rechazado', function () {
    subscriptionActingAsAdmin();
    $company = Company::factory()->create();

    test()->postJson(route('admin.companies.subscription', $company), [
        'plan' => PlanTypes::FINANCIERO,
    ])->assertUnprocessable()->assertJsonValidationErrors(['ends_at', 'amount']);
});

test('asignar plan gratuito sin fecha crea trial de un mes', function () {
    subscriptionActingAsAdmin();
    $company = Company::factory()->withoutSubscription()->create();

    test()->postJson(route('admin.companies.subscription', $company), [
        'plan' => PlanTypes::GRATUITO,
    ])->assertCreated();

    expect($company->currentSubscription()->ended_at->toDateString())->toBe(now()->addMonth()->toDateString());
});

test('el listado admin de empresas incluye la última suscripción, vigente o vencida, o null si nunca tuvo', function () {
    subscriptionActingAsAdmin();
    $withPlan = Company::factory()->plan(PlanTypes::FACTURADOR)->create();
    $expired = Company::factory()->withoutSubscription()->create();
    $expired->subscriptions()->create([
        'plan' => PlanTypes::GRATUITO,
        'started_at' => now()->subMonths(3),
        'ended_at' => now()->subMonths(2),
    ]);
    $expired->subscriptions()->create([
        'plan' => PlanTypes::FINANCIERO,
        'started_at' => now()->subMonths(2),
        'ended_at' => now()->subDay(),
    ]);
    $neverHadPlan = Company::factory()->withoutSubscription()->create();

    $companies = collect(test()->getJson(route('admin.companies.index'))->assertOk()->json('data'))->keyBy('id');

    expect($companies[$withPlan->id]['last_subscription']['plan'])->toBe(PlanTypes::FACTURADOR)
        ->and($companies[$withPlan->id]['last_subscription']['ended_at'])->toBe(now()->addYear()->format('Y-m-d'))
        ->and($companies[$expired->id]['last_subscription']['plan'])->toBe(PlanTypes::FINANCIERO)
        ->and($companies[$expired->id]['last_subscription']['ended_at'])->toBe(now()->subDay()->format('Y-m-d'))
        ->and($companies[$neverHadPlan->id]['last_subscription'])->toBeNull();
});

test('el listado admin carga las suscripciones sin N+1', function () {
    subscriptionActingAsAdmin();
    Company::factory()->count(5)->create();

    DB::enableQueryLog();
    test()->getJson(route('admin.companies.index'))->assertOk();
    $subscriptionQueries = collect(DB::getQueryLog())->filter(fn ($query) => str_contains($query['query'], 'subscriptions'));

    expect($subscriptionQueries)->toHaveCount(1);
});

test('asignar un plan guarda el monto pagado', function () {
    subscriptionActingAsAdmin();
    $company = Company::factory()->create();

    test()->postJson(route('admin.companies.subscription', $company), [
        'plan' => PlanTypes::FINANCIERO,
        'ends_at' => now()->addYear()->format('Y-m-d'),
        'amount' => 199.99,
    ])->assertCreated()->assertJsonPath('amount', 199.99);

    expect($company->currentSubscription()->amount)->toBe(199.99);
});

test('asignar un plan con monto negativo es rechazado', function () {
    subscriptionActingAsAdmin();
    $company = Company::factory()->create();

    test()->postJson(route('admin.companies.subscription', $company), [
        'plan' => PlanTypes::FINANCIERO,
        'ends_at' => now()->addYear()->format('Y-m-d'),
        'amount' => -1,
    ])->assertUnprocessable()->assertJsonValidationErrors('amount');
});

test('el listado admin de empresas incluye el usuario principal o null', function () {
    subscriptionActingAsAdmin();
    $company = Company::factory()->create();
    $clientType = UserType::firstOrCreate(['type' => 'client']);
    $owner = User::factory()->create(['user' => 'dueno_empresa', 'user_type_id' => $clientType->id]);
    $secondary = User::factory()->create(['user_type_id' => $clientType->id]);
    CompanyUser::create(['user_id' => $owner->id, 'level' => 'owner', 'level_id' => $company->id]);
    CompanyUser::create(['user_id' => $secondary->id, 'level' => 'owner', 'level_id' => $company->id]);
    $withoutUser = Company::factory()->create();

    $companies = collect(test()->getJson(route('admin.companies.index'))->assertOk()->json('data'))->keyBy('id');

    expect($companies[$company->id]['user'])->toBe(['id' => $owner->id, 'user' => 'dueno_empresa', 'email' => $owner->email])
        ->and($companies[$withoutUser->id]['user'])->toBeNull();
});
