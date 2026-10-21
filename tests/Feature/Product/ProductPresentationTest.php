<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Product\Product;
use App\Models\Product\ProductPresentation;
use App\Models\User;
use App\Models\UserType;
use Illuminate\Support\Facades\DB;

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

test('el código de la presentación no se repite en la sucursal', function () {
    [, $product] = presentationActingAsCompany();
    $otherProduct = Product::factory()->create(['branch_id' => $product->branch_id, 'code' => 'PROD-01']);
    ProductPresentation::factory()->for($otherProduct)->create(['code' => '7861111111111']);

    test()->postJson(route('products.presentations.store', $product), [
        'name' => 'Caja x24', 'conversion_factor' => 24, 'code' => '7861111111111',
    ])->assertUnprocessable()->assertJsonValidationErrors('code');

    test()->postJson(route('products.presentations.store', $product), [
        'name' => 'Caja x24', 'conversion_factor' => 24, 'code' => 'PROD-01',
    ])->assertUnprocessable()->assertJsonValidationErrors('code');
});

test('el mismo código se permite en otra sucursal y al editar la propia presentación', function () {
    [, $product] = presentationActingAsCompany();
    $foreignProduct = Product::factory()->create();
    ProductPresentation::factory()->for($foreignProduct)->create(['code' => '7862222222222']);

    test()->postJson(route('products.presentations.store', $product), [
        'name' => 'Caja x24', 'conversion_factor' => 24, 'code' => '7862222222222',
    ])->assertCreated();

    $presentation = ProductPresentation::where('product_id', $product->id)->first();

    test()->putJson(route('products.presentations.update', [$product, $presentation]), [
        'name' => 'Caja x24 nueva', 'conversion_factor' => 24, 'code' => '7862222222222',
    ])->assertSuccessful();
});

test('se puede desactivar una presentación', function () {
    [, $product] = presentationActingAsCompany();
    $presentation = ProductPresentation::factory()->for($product)->create();

    test()->putJson(route('products.presentations.update', [$product, $presentation]), [
        'name' => $presentation->name, 'conversion_factor' => $presentation->conversion_factor, 'active' => false,
    ])->assertSuccessful()->assertJsonPath('active', false);

    expect($presentation->fresh()->active)->toBeFalse();
});

test('el factor de conversión acepta como máximo 6 decimales', function () {
    [, $product] = presentationActingAsCompany();

    test()->postJson(route('products.presentations.store', $product), [
        'name' => 'Fracción', 'conversion_factor' => 0.0833333,
    ])->assertUnprocessable()->assertJsonValidationErrors('conversion_factor');
});

/**
 * @param  array<int, array<string, mixed>>  $presentations
 * @return array<string, mixed>
 */
function productWithPresentationsPayload(array $presentations, array $overrides = []): array
{
    DB::table('iva_taxes')->insertOrIgnore([['code' => 2, 'percentage' => 12, 'state' => 'active']]);

    return [
        'code' => 'HUE', 'type_product' => 1, 'name' => 'Huevos', 'price1' => 0.15, 'iva' => 2,
        'presentations' => $presentations,
        ...$overrides,
    ];
}

test('crear producto con presentaciones en una sola petición', function () {
    presentationActingAsCompany();

    $response = test()->postJson(route('products.store'), productWithPresentationsPayload([
        ['name' => 'Cubeta x30', 'conversion_factor' => 30, 'price' => 4.5, 'code' => '7861234567890'],
        ['name' => 'Media cubeta', 'conversion_factor' => 15, 'active' => false],
    ]));

    $response->assertCreated()
        ->assertJsonPath('code', 'HUE')
        ->assertJsonCount(2, 'presentations')
        ->assertJsonPath('presentations.0.code', '7861234567890')
        ->assertJsonPath('presentations.0.active', true)
        ->assertJsonPath('presentations.1.price', 2.25)
        ->assertJsonPath('presentations.1.active', false);

    expect(ProductPresentation::where('product_id', $response->json('id'))->count())->toBe(2);
});

test('si una presentación es inválida no se crea el producto', function () {
    [, $existing] = presentationActingAsCompany();
    ProductPresentation::factory()->for($existing)->create(['code' => '7860000000001']);

    test()->postJson(route('products.store'), productWithPresentationsPayload([
        ['name' => 'Caja', 'conversion_factor' => 12, 'code' => 'NUEVO'],
        ['name' => 'Caja', 'conversion_factor' => 12, 'code' => '7860000000001'],
        ['name' => 'Caja', 'conversion_factor' => 12, 'code' => 'NUEVO'],
        ['name' => 'Caja', 'conversion_factor' => 12, 'code' => 'HUE'],
        ['conversion_factor' => 0.0000001],
    ]))->assertUnprocessable()->assertJsonValidationErrors([
        'presentations.1.code' => 'El código ya existe en esta sucursal.',
        'presentations.2.code' => 'El código está repetido en otra presentación.',
        'presentations.3.code' => 'El código ya existe en esta sucursal.',
        'presentations.4.name',
        'presentations.4.conversion_factor',
    ]);

    expect(Product::where('code', 'HUE')->exists())->toBeFalse();
});

test('un servicio no puede crearse con presentaciones', function () {
    presentationActingAsCompany();

    test()->postJson(route('products.store'), productWithPresentationsPayload(
        [['name' => 'Paquete', 'conversion_factor' => 10]],
        ['type_product' => 2],
    ))->assertUnprocessable()->assertJsonValidationErrors(['presentations' => 'Un servicio no puede tener presentaciones.']);
});

test('el listado de productos solo trae presentaciones activas', function () {
    [, $product] = presentationActingAsCompany();
    DB::table('iva_taxes')->insertOrIgnore([['code' => $product->iva, 'percentage' => 0, 'state' => 'active']]);
    ProductPresentation::factory()->for($product)->create(['name' => 'Caja x12', 'code' => 'ACT-1']);
    ProductPresentation::factory()->for($product)->inactive()->create(['name' => 'Caja x6', 'code' => 'INA-1']);

    test()->getJson(route('products.index'))
        ->assertSuccessful()
        ->assertJsonCount(1, 'data.0.presentations')
        ->assertJsonPath('data.0.presentations.0.name', 'Caja x12');

    test()->getJson(route('products.index', ['search' => 'INA-1']))
        ->assertSuccessful()
        ->assertJsonCount(0, 'data');
});

test('los códigos de presentaciones no se repiten aunque cambien mayúsculas', function () {
    presentationActingAsCompany();

    test()->postJson(route('products.store'), productWithPresentationsPayload([
        ['name' => 'Caja', 'conversion_factor' => 12, 'code' => 'caja-12'],
        ['name' => 'Caja grande', 'conversion_factor' => 24, 'code' => 'CAJA-12'],
        ['name' => 'Unidad', 'conversion_factor' => 2, 'code' => 'hue'],
    ]))->assertUnprocessable()->assertJsonValidationErrors([
        'presentations.0.code' => 'El código está repetido en otra presentación.',
        'presentations.1.code' => 'El código está repetido en otra presentación.',
        'presentations.2.code' => 'El código ya existe en esta sucursal.',
    ]);
});

test('el código de un producto no puede repetir el de una presentación de la sucursal', function () {
    [, $product] = presentationActingAsCompany();
    ProductPresentation::factory()->for($product)->create(['code' => '7863333333333']);
    $other = Product::factory()->create(['branch_id' => $product->branch_id]);

    test()->postJson(route('products.store'), productWithPresentationsPayload([], ['code' => '7863333333333']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('code');

    test()->putJson(route('products.update', $other), productWithPresentationsPayload([], ['code' => '7863333333333']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('code');
});
