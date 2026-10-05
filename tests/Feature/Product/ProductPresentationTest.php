<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Product\Product;
use App\Models\Product\ProductPresentation;
use App\Models\User;
use App\Models\UserType;

/**
 * @return array{0: Branch, 1: Product}
 */
function presentationActingAsCompany(): array
{
    $userType = UserType::firstOrCreate(['type' => 'client']);
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();
    $user = User::factory()->create(['user_type_id' => $userType->id]);
    CompanyUser::create(['user_id' => $user->id, 'level' => 1, 'level_id' => $company->id]);
    test()->actingAs($user);

    $product = Product::factory()->create(['branch_id' => $branch->id, 'price1' => 10]);

    return [$branch, $product];
}

test('crear presentación calcula precio desde price1 si no se especifica uno propio', function () {
    [, $product] = presentationActingAsCompany();

    $response = test()->postJson(route('products.presentations.store', $product), [
        'name' => 'Caja x24',
        'conversion_factor' => 24,
    ]);

    $response->assertCreated();
    expect((float) $response->json('price'))->toBe(240.0);
});

test('crear presentación con precio propio no lo recalcula', function () {
    [, $product] = presentationActingAsCompany();

    $response = test()->postJson(route('products.presentations.store', $product), [
        'name' => 'Caja x24 (oferta)',
        'conversion_factor' => 24,
        'price' => 200,
    ]);

    $response->assertCreated();
    expect((float) $response->json('price'))->toBe(200.0);
});

test('listar presentaciones de un producto', function () {
    [, $product] = presentationActingAsCompany();

    ProductPresentation::factory()->for($product)->create(['name' => 'Caja x12', 'conversion_factor' => 12]);
    ProductPresentation::factory()->for($product)->create(['name' => 'Paquete x6', 'conversion_factor' => 6]);

    $response = test()->getJson(route('products.presentations.index', $product));

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(2);
});

test('no se puede borrar la presentación de otro producto', function () {
    [, $product] = presentationActingAsCompany();
    $otherProduct = Product::factory()->create(['branch_id' => $product->branch_id]);
    $presentation = ProductPresentation::factory()->for($otherProduct)->create();

    $response = test()->deleteJson(route('products.presentations.destroy', [$product, $presentation]));

    $response->assertNotFound();
});
