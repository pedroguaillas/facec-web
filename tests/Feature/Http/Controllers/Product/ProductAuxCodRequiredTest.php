<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\User;
use App\Models\UserType;
use Illuminate\Support\Facades\DB;

function auxCodActingAsCompany(array $companyAttributes = []): Branch
{
    $userType = UserType::firstOrCreate(['type' => 'client']);
    $company = Company::factory()->create($companyAttributes);
    $branch = Branch::factory()->for($company)->create();
    $user = User::factory()->create(['user_type_id' => $userType->id]);
    CompanyUser::create(['user_id' => $user->id, 'level' => 1, 'level_id' => $company->id]);

    test()->actingAs($user);

    DB::table('iva_taxes')->insertOrIgnore([
        ['code' => 2, 'percentage' => 12, 'state' => 'active'],
        ['code' => 5, 'percentage' => 5, 'state' => 'active'],
    ]);

    return $branch;
}

test('aux_cod es obligatorio si el producto es bien (type_product=1) e iva=5%', function () {
    auxCodActingAsCompany(['base5' => true]);

    $response = $this->postJson(route('products.store'), [
        'code' => 'P001',
        'type_product' => 1,
        'name' => 'Producto ferretería',
        'price1' => 10,
        'iva' => 5,
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('aux_cod');
});

test('aux_cod NO es obligatorio si el producto es servicio (type_product=2) aunque la empresa sea de transporte', function () {
    auxCodActingAsCompany(['transport' => true]);

    $response = $this->postJson(route('products.store'), [
        'code' => 'P002',
        'type_product' => 2,
        'name' => 'Servicio de transporte',
        'price1' => 10,
        'iva' => 2,
    ]);

    $response->assertCreated();
});

test('aux_cod NO es obligatorio si el producto es bien pero iva no es 5%', function () {
    auxCodActingAsCompany();

    $response = $this->postJson(route('products.store'), [
        'code' => 'P003',
        'type_product' => 1,
        'name' => 'Producto normal',
        'price1' => 10,
        'iva' => 2,
    ]);

    $response->assertCreated();
});

test('aux_cod presente satisface la regla cuando es obligatorio', function () {
    auxCodActingAsCompany(['base5' => true]);

    $response = $this->postJson(route('products.store'), [
        'code' => 'P004',
        'type_product' => 1,
        'name' => 'Producto ferretería',
        'price1' => 10,
        'iva' => 5,
        'aux_cod' => 'F010101',
    ]);

    $response->assertCreated();
});
