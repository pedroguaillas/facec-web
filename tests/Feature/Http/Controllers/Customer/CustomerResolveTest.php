<?php

use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\User;
use App\Models\UserType;
use Illuminate\Support\Facades\Http;

function customerResolveActingAsCompany(): Company
{
    $userType = UserType::firstOrCreate(['type' => 'client']);
    $company = Company::factory()->create();
    $user = User::factory()->create(['user_type_id' => $userType->id]);
    CompanyUser::create(['user_id' => $user->id, 'level' => 1, 'level_id' => $company->id]);

    test()->actingAs($user);

    return $company;
}

test('resolve consulta al SRI con cédula de 10 dígitos (tipoIdentificacion C)', function () {
    customerResolveActingAsCompany();

    Http::fake(['*' => Http::response(['nombreCompleto' => 'JUAN PEREZ'], 200)]);

    $response = $this->getJson(route('customers.resolve', '1234567890'));

    $response->assertOk();
    Http::assertSent(fn ($request) => $request['numeroIdentificacion'] === '1234567890'
        && $request['tipoIdentificacion'] === 'C');
});

test('resolve consulta al SRI con RUC de 13 dígitos (tipoIdentificacion R)', function () {
    customerResolveActingAsCompany();

    Http::fake(['*' => Http::response(['nombreCompleto' => 'EMPRESA SA'], 200)]);

    $response = $this->getJson(route('customers.resolve', '1234567890001'));

    $response->assertOk();
    Http::assertSent(fn ($request) => $request['numeroIdentificacion'] === '1234567890001'
        && $request['tipoIdentificacion'] === 'R');
});

test('resolve no consulta al SRI si la identificación no tiene 10 ni 13 dígitos', function () {
    customerResolveActingAsCompany();

    Http::fake();

    $response = $this->getJson(route('customers.resolve', '12345'));

    $response->assertOk();
    Http::assertNothingSent();
});

test('resolve no consulta al SRI si la identificación no es solo dígitos', function () {
    customerResolveActingAsCompany();

    Http::fake();

    $response = $this->getJson(route('customers.resolve', '123456789A'));

    $response->assertOk();
    Http::assertNothingSent();
});
