<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Product\Product;
use App\Models\Provider;
use App\Models\User;
use App\Models\UserType;

/**
 * @return array{0: Branch, 1: Provider, 2: Product}
 */
function providerCodeActingAsCompany(): array
{
    $userType = UserType::firstOrCreate(['type' => 'client']);
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();
    $user = User::factory()->create(['user_type_id' => $userType->id]);
    CompanyUser::create(['user_id' => $user->id, 'level' => 1, 'level_id' => $company->id]);
    test()->actingAs($user);

    $provider = Provider::factory()->create(['branch_id' => $branch->id]);
    $product = Product::factory()->create(['branch_id' => $branch->id]);

    return [$branch, $provider, $product];
}

test('registrar código de proveedor y resolverlo por código', function () {
    [, $provider, $product] = providerCodeActingAsCompany();

    $response = test()->postJson(route('providers.product-codes.store', $provider), [
        'product_id' => $product->id,
        'code' => 'PROV-XYZ-1',
    ]);

    $response->assertCreated();

    $lookup = test()->getJson(route('providers.product-codes.index', $provider).'?code=PROV-XYZ-1');

    $lookup->assertOk();
    expect($lookup->json())->toHaveCount(1)
        ->and($lookup->json('0.product_id'))->toBe($product->id);
});

test('el mismo código no se puede repetir para el mismo proveedor', function () {
    [, $provider, $product] = providerCodeActingAsCompany();

    test()->postJson(route('providers.product-codes.store', $provider), [
        'product_id' => $product->id,
        'code' => 'DUP-1',
    ])->assertCreated();

    $otherProduct = Product::factory()->create(['branch_id' => $product->branch_id]);

    $response = test()->postJson(route('providers.product-codes.store', $provider), [
        'product_id' => $otherProduct->id,
        'code' => 'DUP-1',
    ]);

    $response->assertUnprocessable();
});

test('el mismo código sí puede repetirse entre proveedores distintos', function () {
    [$branch, $provider, $product] = providerCodeActingAsCompany();
    $otherProvider = Provider::factory()->create(['branch_id' => $branch->id]);

    test()->postJson(route('providers.product-codes.store', $provider), [
        'product_id' => $product->id,
        'code' => 'SHARED-1',
    ])->assertCreated();

    $response = test()->postJson(route('providers.product-codes.store', $otherProvider), [
        'product_id' => $product->id,
        'code' => 'SHARED-1',
    ]);

    $response->assertCreated();
});
