<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\EmisionPoint;
use App\Models\User;
use App\Models\UserType;
use App\StaticClasses\VoucherTypes;

test('shops.create no expone preview de secuencial, solo id/store/point/recognition', function () {
    $userType = UserType::firstOrCreate(['type' => 'client']);
    $company = Company::factory()->create();
    $user = User::factory()->create(['user_type_id' => $userType->id]);
    CompanyUser::create(['user_id' => $user->id, 'level' => 1, 'level_id' => $company->id]);
    $this->actingAs($user);

    $branch = Branch::factory()->for($company)->create();
    $point = EmisionPoint::factory()->create(['branch_id' => $branch->id]);

    $response = $this->getJson(route('shops.create'));

    $response->assertOk();
    $pointData = collect($response->json('points'))->firstWhere('id', $point->id);

    expect($pointData)->not->toBeNull()
        ->and($pointData)->not->toHaveKey('retention')
        ->and($pointData)->not->toHaveKey('settlementonpurchase');
});

test('shops.create devuelve los tipos de comprobante de compras para armar el selector', function () {
    $userType = UserType::firstOrCreate(['type' => 'client']);
    $company = Company::factory()->create();
    $user = User::factory()->create(['user_type_id' => $userType->id]);
    CompanyUser::create(['user_id' => $user->id, 'level' => 1, 'level_id' => $company->id]);
    $this->actingAs($user);

    $response = $this->getJson(route('shops.create'));

    $response->assertOk();
    $slugs = collect($response->json('voucherTypes'))->pluck('slug')->sort()->values()->toArray();

    expect($slugs)->toBe(collect([
        VoucherTypes::COMPRA_FACTURA,
        VoucherTypes::COMPRA_NOTA_VENTA,
        VoucherTypes::COMPRA_LIQUIDACION_COMPRA,
        VoucherTypes::COMPRA_NOTA_DEBITO,
    ])->sort()->values()->toArray());
});
