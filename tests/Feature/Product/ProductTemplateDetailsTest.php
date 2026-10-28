<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Product\Product;
use App\Models\Product\ProductDetail;
use App\Models\Product\ProductTemplate;
use App\Models\Product\ProductTemplateField;
use App\Models\User;
use App\Models\UserType;
use Illuminate\Support\Facades\DB;

function detailsActingAsCompany(): Branch
{
    $userType = UserType::firstOrCreate(['type' => 'client']);
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();
    $user = User::factory()->create(['user_type_id' => $userType->id]);
    CompanyUser::create(['user_id' => $user->id, 'level' => 1, 'level_id' => $company->id]);
    test()->actingAs($user);

    DB::table('iva_taxes')->insertOrIgnore([['code' => 2, 'percentage' => 12, 'state' => 'active']]);

    return $branch;
}

function detailsMotoTemplate(Branch $branch): ProductTemplate
{
    $template = ProductTemplate::factory()->for($branch)->create(['name' => 'Moto', 'code_prefix' => 'M']);
    $field = fn (array $attributes) => ProductTemplateField::factory()->for($template, 'template')->create($attributes);

    $field(['key' => 'marca', 'label' => 'Marca', 'required' => true, 'sort' => 1]);
    $field(['key' => 'ano', 'label' => 'Año', 'type' => 'year', 'required' => true, 'sort' => 2]);
    $chasis = $field(['key' => 'chasis', 'label' => 'Chasis', 'level' => 'unidad', 'required' => true, 'unique' => true, 'in_sri' => true, 'sort' => 3]);
    $template->update(['code_field_id' => $chasis->id]);

    return $template;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function detailsProductPayload(array $overrides = []): array
{
    return array_merge([
        'code' => 'M1R1',
        'type_product' => Product::TYPE_PRODUCT,
        'name' => 'MOTO M1 R1 2026',
        'price1' => 2500,
        'iva' => 2,
    ], $overrides);
}

test('crea un producto modelo con plantilla y sus características de nivel modelo', function () {
    $branch = detailsActingAsCompany();
    $template = detailsMotoTemplate($branch);

    $response = $this->postJson(route('products.store'), detailsProductPayload([
        'product_template_id' => $template->id,
        'details' => ['marca' => ' M1 ', 'ano' => '2026', 'chasis' => 'ignorado'],
    ]));

    $response->assertCreated()->assertJsonPath('details', ['marca' => 'M1', 'ano' => '2026']);

    $product = Product::where('code', 'M1R1')->first();
    expect($product->product_template_id)->toBe($template->id)
        ->and($product->details()->count())->toBe(2);
});

test('valida obligatorios y tipos de las características del modelo', function () {
    $branch = detailsActingAsCompany();
    $template = detailsMotoTemplate($branch);

    $this->postJson(route('products.store'), detailsProductPayload([
        'product_template_id' => $template->id,
        'details' => ['ano' => '26'],
    ]))->assertUnprocessable()
        ->assertJsonValidationErrors([
            'details.marca' => 'Marca es obligatorio.',
            'details.ano' => 'Año debe ser un año de 4 dígitos.',
        ]);
});

test('un modelo con campos de unidad no acepta stock propio', function () {
    $branch = detailsActingAsCompany();
    $template = detailsMotoTemplate($branch);

    $this->postJson(route('products.store'), detailsProductPayload([
        'product_template_id' => $template->id,
        'details' => ['marca' => 'M1', 'ano' => '2026'],
        'stock' => 5,
    ]))->assertUnprocessable()->assertJsonValidationErrors('stock');
});

test('no acepta la plantilla de otra empresa ni una inactiva', function () {
    $branch = detailsActingAsCompany();
    $foreign = ProductTemplate::factory()->create();
    $inactive = ProductTemplate::factory()->for($branch)->create(['active' => false]);

    $this->postJson(route('products.store'), detailsProductPayload(['product_template_id' => $foreign->id]))
        ->assertUnprocessable()->assertJsonValidationErrors('product_template_id');
    $this->postJson(route('products.store'), detailsProductPayload(['product_template_id' => $inactive->id]))
        ->assertUnprocessable()->assertJsonValidationErrors('product_template_id');
});

test('editar sin enviar plantilla ni detalles no toca las características', function () {
    $branch = detailsActingAsCompany();
    $template = detailsMotoTemplate($branch);
    $product = Product::factory()->create(['branch_id' => $branch->id, 'product_template_id' => $template->id, 'iva' => 2]);
    ProductDetail::factory()->create(['product_id' => $product->id, 'product_template_field_id' => $template->fields()->where('key', 'marca')->value('id'), 'value' => 'M1']);

    $this->putJson(route('products.update', $product), detailsProductPayload(['code' => $product->code, 'name' => 'Nuevo nombre']))
        ->assertOk();

    expect($product->fresh()->product_template_id)->toBe($template->id)
        ->and($product->details()->value('value'))->toBe('M1');
});

test('editar actualiza las características y quitar la plantilla las borra', function () {
    $branch = detailsActingAsCompany();
    $template = detailsMotoTemplate($branch);
    $product = Product::factory()->create(['branch_id' => $branch->id, 'product_template_id' => $template->id, 'iva' => 2]);

    $this->putJson(route('products.update', $product), detailsProductPayload([
        'code' => $product->code,
        'product_template_id' => $template->id,
        'details' => ['marca' => 'Honda', 'ano' => '2025'],
    ]))->assertOk();

    $this->getJson(route('products.edit', $product))
        ->assertOk()
        ->assertJsonPath('details', ['marca' => 'Honda', 'ano' => '2025'])
        ->assertJsonPath('productTemplates.0.name', 'Moto');

    $this->putJson(route('products.update', $product), detailsProductPayload([
        'code' => $product->code,
        'product_template_id' => null,
    ]))->assertOk();

    expect($product->fresh()->product_template_id)->toBeNull()
        ->and($product->details()->count())->toBe(0);
});

test('no cambia de plantilla ni se elimina un modelo con unidades', function () {
    $branch = detailsActingAsCompany();
    $template = detailsMotoTemplate($branch);
    $other = ProductTemplate::factory()->for($branch)->create();
    $model = Product::factory()->create(['branch_id' => $branch->id, 'product_template_id' => $template->id, 'iva' => 2]);
    Product::factory()->create(['branch_id' => $branch->id, 'parent_product_id' => $model->id]);

    $this->putJson(route('products.update', $model), detailsProductPayload([
        'code' => $model->code,
        'product_template_id' => $other->id,
    ]))->assertUnprocessable()->assertJsonValidationErrors('product_template_id');

    $this->deleteJson(route('products.destroy', $model))
        ->assertUnprocessable()
        ->assertJsonPath('message', 'El producto tiene unidades registradas y no se puede eliminar.');
});
