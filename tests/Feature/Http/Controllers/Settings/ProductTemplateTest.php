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

function templateActingAsCompany(): Branch
{
    $userType = UserType::firstOrCreate(['type' => 'client']);
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();
    $user = User::factory()->create(['user_type_id' => $userType->id]);
    CompanyUser::create(['user_id' => $user->id, 'level' => 1, 'level_id' => $company->id]);

    test()->actingAs($user);

    return $branch;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function motoTemplatePayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Moto',
        'code_prefix' => 'M',
        'code_field_key' => 'chasis',
        'fields' => [
            ['label' => 'Marca', 'type' => 'text', 'level' => 'modelo', 'required' => true],
            ['label' => 'Año', 'type' => 'year', 'level' => 'modelo', 'required' => true],
            ['label' => 'Modelo', 'type' => 'text', 'level' => 'modelo', 'required' => true],
            ['label' => 'Chasis', 'type' => 'text', 'level' => 'unidad', 'required' => true, 'unique' => true, 'in_sri' => true],
            ['label' => 'Motor', 'type' => 'text', 'level' => 'unidad', 'required' => true, 'unique' => true, 'in_sri' => true],
            ['label' => 'RAMV', 'type' => 'text', 'level' => 'unidad', 'unique' => true, 'in_sri' => true],
        ],
    ], $overrides);
}

test('crea una plantilla con sus campos, keys derivadas del label y campo de código', function () {
    $branch = templateActingAsCompany();

    $response = $this->postJson(route('settings.product-templates.store'), motoTemplatePayload());

    $response->assertCreated()
        ->assertJsonPath('name', 'Moto')
        ->assertJsonPath('code_prefix', 'M')
        ->assertJsonCount(6, 'fields')
        ->assertJsonPath('fields.1.key', 'ano')
        ->assertJsonPath('fields.3.key', 'chasis');

    $template = ProductTemplate::first();
    expect($template->branch_id)->toBe($branch->id)
        ->and($template->codeField->key)->toBe('chasis')
        ->and($template->fields()->where('in_sri', true)->pluck('key')->all())->toBe(['chasis', 'motor', 'ramv']);
});

test('code_prefix es opcional', function () {
    templateActingAsCompany();

    $this->postJson(route('settings.product-templates.store'), motoTemplatePayload(['code_prefix' => null]))
        ->assertCreated()
        ->assertJsonPath('code_prefix', null);
});

test('rechaza más de 3 características para el SRI', function () {
    templateActingAsCompany();

    $payload = motoTemplatePayload();
    $payload['fields'][0]['in_sri'] = true;

    $this->postJson(route('settings.product-templates.store'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['fields' => 'Solo 3 características pueden ir en la información adicional del SRI.']);
});

test('un campo inactivo no cuenta para el máximo del SRI', function () {
    templateActingAsCompany();

    $payload = motoTemplatePayload();
    $payload['fields'][0]['in_sri'] = true;
    $payload['fields'][0]['active'] = false;

    $this->postJson(route('settings.product-templates.store'), $payload)->assertCreated();
});

test('el campo del código debe ser de la unidad y único', function () {
    templateActingAsCompany();

    $this->postJson(route('settings.product-templates.store'), motoTemplatePayload(['code_field_key' => 'marca']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('code_field_key');
});

test('rechaza keys repetidas y nombre repetido en la empresa', function () {
    $branch = templateActingAsCompany();
    ProductTemplate::factory()->for($branch)->create(['name' => 'Moto']);

    $payload = motoTemplatePayload();
    $payload['fields'][1]['key'] = 'marca';

    $this->postJson(route('settings.product-templates.store'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'fields.1.key']);
});

test('lista solo las plantillas de la empresa del usuario', function () {
    $branch = templateActingAsCompany();
    ProductTemplate::factory()->for($branch)->create(['name' => 'Moto']);
    ProductTemplate::factory()->create(['name' => 'Ajena']);

    $this->getJson(route('settings.product-templates.index'))
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.name', 'Moto');
});

test('no permite ver ni editar la plantilla de otra empresa', function () {
    templateActingAsCompany();
    $foreign = ProductTemplate::factory()->create();

    $this->getJson(route('settings.product-templates.show', $foreign))->assertNotFound();
    $this->putJson(route('settings.product-templates.update', $foreign), motoTemplatePayload())->assertNotFound();
});

test('actualiza campos existentes, crea nuevos y elimina los que no vienen', function () {
    templateActingAsCompany();
    $template = ProductTemplate::find($this->postJson(route('settings.product-templates.store'), motoTemplatePayload())->json('id'));
    $marca = $template->fields()->where('key', 'marca')->first();

    $this->putJson(route('settings.product-templates.update', $template), [
        'name' => 'Motocicleta',
        'code_prefix' => 'MT',
        'code_field_key' => 'chasis',
        'fields' => [
            ['id' => $marca->id, 'key' => 'marca', 'label' => 'Marca comercial', 'type' => 'text', 'level' => 'modelo'],
            ['label' => 'Chasis', 'type' => 'text', 'level' => 'unidad', 'unique' => true, 'in_sri' => true],
            ['label' => 'Color', 'type' => 'text', 'level' => 'modelo'],
        ],
    ])->assertOk()->assertJsonCount(3, 'fields');

    $template->refresh();
    expect($template->name)->toBe('Motocicleta')
        ->and($template->fields()->pluck('key')->all())->toBe(['marca', 'chasis', 'color'])
        ->and($marca->fresh()->label)->toBe('Marca comercial')
        ->and($template->codeField->key)->toBe('chasis');
});

test('no elimina ni cambia de nivel un campo con valores registrados', function () {
    $branch = templateActingAsCompany();
    $template = ProductTemplate::factory()->for($branch)->create();
    $chasis = ProductTemplateField::factory()->for($template, 'template')->uniqueUnit()->create(['key' => 'chasis', 'label' => 'Chasis']);
    $marca = ProductTemplateField::factory()->for($template, 'template')->create(['key' => 'marca', 'label' => 'Marca']);
    ProductDetail::factory()->create([
        'product_id' => Product::factory()->create(['branch_id' => $branch->id])->id,
        'product_template_field_id' => $chasis->id,
    ]);

    $this->putJson(route('settings.product-templates.update', $template), [
        'name' => $template->name,
        'fields' => [
            ['id' => $marca->id, 'key' => 'marca', 'label' => 'Marca', 'type' => 'text', 'level' => 'modelo'],
        ],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['fields' => 'La característica "Chasis" ya tiene valores registrados: desactívala en lugar de eliminarla.']);

    $this->putJson(route('settings.product-templates.update', $template), [
        'name' => $template->name,
        'fields' => [
            ['id' => $chasis->id, 'key' => 'chasis', 'label' => 'Chasis', 'type' => 'text', 'level' => 'modelo'],
            ['id' => $marca->id, 'key' => 'marca', 'label' => 'Marca', 'type' => 'text', 'level' => 'modelo'],
        ],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['fields' => 'La característica "Chasis" ya tiene valores registrados: no se puede cambiar su clave ni su nivel.']);

    $this->putJson(route('settings.product-templates.update', $template), [
        'name' => $template->name,
        'fields' => [
            ['id' => $chasis->id, 'key' => 'chasis', 'label' => 'Chasis', 'type' => 'text', 'level' => 'unidad', 'active' => false],
        ],
    ])->assertOk();

    expect($chasis->fresh()->active)->toBeFalse()
        ->and(ProductTemplateField::find($marca->id))->toBeNull();
});

test('no acepta ids de campos de otra plantilla', function () {
    $branch = templateActingAsCompany();
    $template = ProductTemplate::factory()->for($branch)->create();
    $otherField = ProductTemplateField::factory()->create();

    $this->putJson(route('settings.product-templates.update', $template), [
        'name' => $template->name,
        'fields' => [
            ['id' => $otherField->id, 'key' => 'marca', 'label' => 'Marca', 'type' => 'text', 'level' => 'modelo'],
        ],
    ])->assertUnprocessable()->assertJsonValidationErrors('fields.0.id');
});

test('elimina una plantilla sin productos y bloquea si tiene productos', function () {
    $branch = templateActingAsCompany();
    $unused = ProductTemplate::factory()->for($branch)->create();
    $used = ProductTemplate::factory()->for($branch)->create();
    Product::factory()->create(['branch_id' => $branch->id, 'product_template_id' => $used->id]);

    $this->deleteJson(route('settings.product-templates.destroy', $unused))->assertOk();
    $this->deleteJson(route('settings.product-templates.destroy', $used))
        ->assertUnprocessable()
        ->assertJsonPath('succes', false);

    expect(ProductTemplate::find($unused->id))->toBeNull()
        ->and(ProductTemplate::find($used->id))->not->toBeNull();
});
