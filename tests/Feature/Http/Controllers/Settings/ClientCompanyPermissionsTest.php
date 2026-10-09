<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\User;
use App\Models\UserType;

function clientPermissionsCompany(array $attributes = []): Company
{
    $userType = UserType::firstOrCreate(['type' => 'client']);
    $company = Company::factory()->create($attributes);
    Branch::factory()->for($company)->create();
    $user = User::factory()->create(['user_type_id' => $userType->id]);
    CompanyUser::create(['user_id' => $user->id, 'level' => 1, 'level_id' => $company->id]);

    test()->actingAs($user);

    return $company;
}

test('el cliente edita sus permisos desde la configuración', function () {
    $company = clientPermissionsCompany([
        'base5' => false, 'base8' => false, 'ice' => false, 'transport' => false, 'repayment' => false,
        'printf' => false, 'guia_in_invoice' => false, 'import_in_invoice' => false, 'import_in_invoices' => false,
    ]);

    $this->putJson(route('settings.company.update'), [
        'base5' => true,
        'base8' => true,
        'ice' => true,
        'transport' => true,
        'repayment' => true,
        'printf' => true,
        'guia_in_invoice' => true,
        'import_in_invoice' => true,
        'import_in_invoices' => true,
    ])->assertOk()
        ->assertJsonPath('base8', true)
        ->assertJsonPath('import_in_invoices', true);

    $company->refresh();

    expect($company->base5)->toBeTrue()
        ->and($company->base8)->toBeTrue()
        ->and((bool) $company->ice)->toBeTrue()
        ->and((bool) $company->transport)->toBeTrue()
        ->and((bool) $company->repayment)->toBeTrue()
        ->and((bool) $company->printf)->toBeTrue()
        ->and((bool) $company->guia_in_invoice)->toBeTrue()
        ->and((bool) $company->import_in_invoice)->toBeTrue()
        ->and((bool) $company->import_in_invoices)->toBeTrue();
});

test('los permisos aceptan "true"/"false" como string (FormData)', function () {
    $company = clientPermissionsCompany(['base5' => false, 'printf' => true]);

    $this->put(route('settings.company.update'), ['base5' => 'true', 'printf' => 'false'], ['Accept' => 'application/json'])
        ->assertOk();

    expect($company->fresh()->base5)->toBeTrue()
        ->and((bool) $company->fresh()->printf)->toBeFalse();
});

test('el cliente no puede cambiar campos reservados al admin', function () {
    $company = clientPermissionsCompany([
        'active' => true, 'active_voucher' => true, 'enviroment_type' => 2, 'decimal' => 6, 'inventory' => false,
    ]);

    $this->putJson(route('settings.company.update'), [
        'active' => false,
        'active_voucher' => false,
        'enviroment_type' => 1,
        'decimal' => 2,
        'inventory' => true,
    ])->assertOk();

    $company->refresh();

    expect((bool) $company->active)->toBeTrue()
        ->and((bool) $company->active_voucher)->toBeTrue()
        ->and((int) $company->enviroment_type)->toBe(2)
        ->and((int) $company->decimal)->toBe(6)
        ->and((bool) $company->inventory)->toBeFalse();
});

test('POST multipart a companies con _method=PUT entra al update (method spoofing)', function () {
    $company = clientPermissionsCompany(['base5' => false]);

    $this->call('POST', '/api/companies', ['_method' => 'PUT', 'base5' => 'true'], [], [], [
        'CONTENT_TYPE' => 'multipart/form-data; boundary=x',
        'HTTP_ACCEPT' => 'application/json',
    ])->assertOk();

    expect($company->fresh()->base5)->toBeTrue();
});
