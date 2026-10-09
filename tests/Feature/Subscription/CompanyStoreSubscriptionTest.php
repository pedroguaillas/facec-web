<?php

use App\Models\Company;
use App\Models\User;
use App\Models\UserType;
use App\StaticClasses\PlanTypes;

function companyStoreActingAsAdmin(): User
{
    $adminType = UserType::firstOrCreate(['type' => 'admin']);
    UserType::firstOrCreate(['type' => 'client']);
    $admin = User::factory()->create(['user_type_id' => $adminType->id]);

    test()->actingAs($admin);

    return $admin;
}

/**
 * @return array<string, mixed>
 */
function companyStorePayload(array $overrides = []): array
{
    $clientType = UserType::firstOrCreate(['type' => 'client']);

    return array_merge([
        'ruc' => '0102030405001',
        'company' => 'Empresa de prueba',
        'user' => 'empresa_prueba',
        'email' => 'empresa@example.com',
        'password' => 'secret123',
        'user_type_id' => $clientType->id,
        'plan' => PlanTypes::FACTURADOR,
        'plan_ends_at' => now()->addYear()->format('Y-m-d'),
        'plan_amount' => 120.5,
    ], $overrides);
}

test('crear empresa persiste ambiente 2 y 6 decimales por defecto', function () {
    companyStoreActingAsAdmin();

    $this->postJson(route('admin.companies.store'), companyStorePayload())->assertCreated();

    $company = Company::where('ruc', '0102030405001')->firstOrFail();

    expect($company->enviroment_type)->toBe(2)
        ->and($company->decimal)->toBe(6);
});

test('crear empresa exige el plan', function () {
    companyStoreActingAsAdmin();

    $payload = companyStorePayload();
    unset($payload['plan']);

    $this->postJson(route('admin.companies.store'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('plan');
});

test('crear empresa con plan pago exige fecha de fin', function () {
    companyStoreActingAsAdmin();

    $payload = companyStorePayload();
    unset($payload['plan_ends_at']);

    $this->postJson(route('admin.companies.store'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('plan_ends_at');
});

test('crear empresa asigna el plan elegido con su fecha de fin', function () {
    companyStoreActingAsAdmin();

    $this->postJson(route('admin.companies.store'), companyStorePayload())
        ->assertCreated()
        ->assertJsonPath('subscription.plan', PlanTypes::FACTURADOR);

    $subscription = Company::where('ruc', '0102030405001')->firstOrFail()->currentSubscription();

    expect($subscription->plan)->toBe(PlanTypes::FACTURADOR)
        ->and($subscription->ended_at->toDateString())->toBe(now()->addYear()->toDateString());
});

test('crear empresa con plan gratuito sin fecha asigna trial de un mes', function () {
    companyStoreActingAsAdmin();

    $this->postJson(route('admin.companies.store'), companyStorePayload(['plan' => PlanTypes::GRATUITO, 'plan_ends_at' => null, 'plan_amount' => null]))
        ->assertCreated();

    $subscription = Company::where('ruc', '0102030405001')->firstOrFail()->currentSubscription();

    expect($subscription->plan)->toBe(PlanTypes::GRATUITO)
        ->and($subscription->ended_at->toDateString())->toBe(now()->addMonth()->toDateString());
});

test('crear empresa persiste el régimen rimpe', function () {
    companyStoreActingAsAdmin();

    $this->postJson(route('admin.companies.store'), companyStorePayload(['rimpe' => 2]))->assertCreated();

    expect(Company::where('ruc', '0102030405001')->firstOrFail()->rimpe)->toBe(2);
});

test('crear empresa devuelve las fechas de la suscripción como Y-m-d', function () {
    companyStoreActingAsAdmin();

    $this->postJson(route('admin.companies.store'), companyStorePayload())
        ->assertCreated()
        ->assertJsonPath('subscription.ended_at', now()->addYear()->format('Y-m-d'));
});

test('crear empresa guarda el monto pagado en la suscripción inicial', function () {
    companyStoreActingAsAdmin();

    $this->postJson(route('admin.companies.store'), companyStorePayload())
        ->assertCreated()
        ->assertJsonPath('subscription.amount', 120.5);

    expect(Company::where('ruc', '0102030405001')->firstOrFail()->currentSubscription()->amount)->toBe(120.5);
});

test('crear empresa con plan pago exige monto', function () {
    companyStoreActingAsAdmin();

    $payload = companyStorePayload();
    unset($payload['plan_amount']);

    $this->postJson(route('admin.companies.store'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('plan_amount');
});

test('crear empresa con plan gratuito sin monto lo guarda en 0', function () {
    companyStoreActingAsAdmin();

    $this->postJson(route('admin.companies.store'), companyStorePayload(['plan' => PlanTypes::GRATUITO, 'plan_ends_at' => null, 'plan_amount' => null]))
        ->assertCreated()
        ->assertJsonPath('subscription.amount', 0);
});
