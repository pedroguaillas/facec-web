<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\EmisionPoint;
use App\Models\Provider;
use App\Models\Shop\Shop;
use App\Models\User;
use App\Models\UserType;
use App\Models\VoucherType;
use App\StaticClasses\VoucherTypes;

function providerActingAsCompany(): Branch
{
    $userType = UserType::firstOrCreate(['type' => 'client']);
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();
    $user = User::factory()->create(['user_type_id' => $userType->id]);
    CompanyUser::create(['user_id' => $user->id, 'level' => 1, 'level_id' => $company->id]);

    test()->actingAs($user);

    return $branch;
}

function shopPayload(Branch $branch, string $voucherTypeSlug, Provider $provider, array $overrides = []): array
{
    $emisionPoint = EmisionPoint::factory()->for($branch)->create();
    $voucherTypeId = VoucherType::where('slug', $voucherTypeSlug)->value('id');

    return array_merge([
        'date' => now()->format('Y-m-d'),
        'voucher_type_id' => $voucherTypeId,
        'provider_id' => $provider->id,
        'point_id' => $emisionPoint->id,
        'serie' => '001-001-000000001',
        'total' => 10,
    ], $overrides);
}

test('liquidación de compra rechaza proveedor con RUC', function () {
    $branch = providerActingAsCompany();
    $provider = Provider::factory()->create(['branch_id' => $branch->id, 'type_identification' => 'ruc']);

    $response = $this->postJson(route('shops.store'), shopPayload($branch, VoucherTypes::COMPRA_LIQUIDACION_COMPRA, $provider));

    $response->assertUnprocessable()->assertJsonValidationErrors('provider_id');
});

test('liquidación de compra acepta proveedor con cédula', function () {
    $branch = providerActingAsCompany();
    $provider = Provider::factory()->create(['branch_id' => $branch->id, 'type_identification' => 'cédula']);

    $response = $this->postJson(route('shops.store'), shopPayload($branch, VoucherTypes::COMPRA_LIQUIDACION_COMPRA, $provider));

    $response->assertCreated();
});

test('liquidación de compra acepta proveedor con pasaporte', function () {
    $branch = providerActingAsCompany();
    $provider = Provider::factory()->create(['branch_id' => $branch->id, 'type_identification' => 'pasaporte']);

    $response = $this->postJson(route('shops.store'), shopPayload($branch, VoucherTypes::COMPRA_LIQUIDACION_COMPRA, $provider));

    $response->assertCreated();
});

test('factura (documento del proveedor) rechaza proveedor sin RUC', function () {
    $branch = providerActingAsCompany();
    $provider = Provider::factory()->create(['branch_id' => $branch->id, 'type_identification' => 'cédula']);

    $response = $this->postJson(route('shops.store'), shopPayload($branch, VoucherTypes::COMPRA_FACTURA, $provider));

    $response->assertUnprocessable()->assertJsonValidationErrors('provider_id');
});

test('factura acepta proveedor con RUC', function () {
    $branch = providerActingAsCompany();
    $provider = Provider::factory()->create(['branch_id' => $branch->id, 'type_identification' => 'ruc']);

    $response = $this->postJson(route('shops.store'), shopPayload($branch, VoucherTypes::COMPRA_FACTURA, $provider));

    $response->assertCreated();
});

test('update rechaza cambiar a un proveedor con RUC si la compra sigue siendo liquidación', function () {
    $branch = providerActingAsCompany();
    $providerCedula = Provider::factory()->create(['branch_id' => $branch->id, 'type_identification' => 'cédula']);
    $providerRuc = Provider::factory()->create(['branch_id' => $branch->id, 'type_identification' => 'ruc']);

    $storeResponse = $this->postJson(route('shops.store'), shopPayload($branch, VoucherTypes::COMPRA_LIQUIDACION_COMPRA, $providerCedula))
        ->assertCreated();

    $shop = Shop::find($storeResponse->json('id'));

    $response = $this->putJson(route('shops.update', $shop), ['provider_id' => $providerRuc->id]);

    $response->assertUnprocessable()->assertJsonValidationErrors('provider_id');
});
