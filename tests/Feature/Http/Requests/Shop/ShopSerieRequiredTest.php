<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\EmisionPoint;
use App\Models\Provider;
use App\Models\User;
use App\Models\UserType;
use App\Models\VoucherType;
use App\StaticClasses\VoucherTypes;

test('liquidación de compra NO requiere serie (la genera el backend)', function () {
    $userType = UserType::firstOrCreate(['type' => 'client']);
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();
    $user = User::factory()->create(['user_type_id' => $userType->id]);
    CompanyUser::create(['user_id' => $user->id, 'level' => 1, 'level_id' => $company->id]);
    $this->actingAs($user);

    $emisionPoint = EmisionPoint::factory()->for($branch)->create();
    $provider = Provider::factory()->create(['branch_id' => $branch->id, 'type_identification' => 'cédula']);
    $liquidacionId = VoucherType::where('slug', VoucherTypes::COMPRA_LIQUIDACION_COMPRA)->value('id');

    $response = $this->postJson(route('shops.store'), [
        'date' => now()->format('Y-m-d'),
        'voucher_type_id' => $liquidacionId,
        'provider_id' => $provider->id,
        'point_id' => $emisionPoint->id,
        'total' => 10,
    ]);

    $response->assertCreated();
    expect($response->json('serie'))->not->toBeNull();
});

test('factura (documento externo) SI requiere serie', function () {
    $userType = UserType::firstOrCreate(['type' => 'client']);
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();
    $user = User::factory()->create(['user_type_id' => $userType->id]);
    CompanyUser::create(['user_id' => $user->id, 'level' => 1, 'level_id' => $company->id]);
    $this->actingAs($user);

    $emisionPoint = EmisionPoint::factory()->for($branch)->create();
    $provider = Provider::factory()->create(['branch_id' => $branch->id, 'type_identification' => 'ruc']);
    $facturaId = VoucherType::where('slug', VoucherTypes::COMPRA_FACTURA)->value('id');

    $response = $this->postJson(route('shops.store'), [
        'date' => now()->format('Y-m-d'),
        'voucher_type_id' => $facturaId,
        'provider_id' => $provider->id,
        'point_id' => $emisionPoint->id,
        'total' => 10,
    ]);

    $response->assertUnprocessable()->assertJsonValidationErrors('serie');
});
