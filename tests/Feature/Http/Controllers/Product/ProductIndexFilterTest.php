<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Product\Product;
use App\Models\User;
use App\Models\UserType;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    DB::table('iva_taxes')->insertOrIgnore([
        ['code' => 2, 'percentage' => 12, 'state' => 'active'],
    ]);

    $userType = UserType::firstOrCreate(['type' => 'client']);
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();
    $user = User::factory()->create(['user_type_id' => $userType->id]);
    CompanyUser::create(['user_id' => $user->id, 'level' => 1, 'level_id' => $company->id]);
    test()->actingAs($user);

    Product::factory()->create(['branch_id' => $branch->id, 'code' => 'PROD-1', 'type_product' => Product::TYPE_PRODUCT]);
    Product::factory()->create(['branch_id' => $branch->id, 'code' => 'SERV-1', 'type_product' => Product::TYPE_SERVICE]);
});

test('sin filtro type, index devuelve productos y servicios', function () {
    $response = $this->getJson(route('products.index'));

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(2);
});

test('type=1 filtra solo productos', function () {
    $response = $this->getJson(route('products.index', ['type' => Product::TYPE_PRODUCT]));

    $response->assertOk();
    $codes = collect($response->json('data'))->pluck('atts.code')->all();
    expect($codes)->toBe(['PROD-1']);
});

test('type=2 filtra solo servicios', function () {
    $response = $this->getJson(route('products.index', ['type' => Product::TYPE_SERVICE]));

    $response->assertOk();
    $codes = collect($response->json('data'))->pluck('atts.code')->all();
    expect($codes)->toBe(['SERV-1']);
});
