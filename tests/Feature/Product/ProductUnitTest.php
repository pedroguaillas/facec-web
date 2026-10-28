<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Inventory;
use App\Models\Order\Order;
use App\Models\Order\OrderItem;
use App\Models\Product\Product;
use App\Models\Product\ProductDetail;
use App\Models\Product\ProductTemplate;
use App\Models\Product\ProductTemplateField;
use App\Models\User;
use App\Models\UserType;
use Illuminate\Support\Facades\DB;

/**
 * @return array{0: Branch, 1: Product, 2: ProductTemplate}
 */
function unitMotoModel(bool $inventory = false, ?string $codePrefix = 'M', bool $withCodeField = true): array
{
    $userType = UserType::firstOrCreate(['type' => 'client']);
    $company = Company::factory()->create(['inventory' => $inventory]);
    $branch = Branch::factory()->for($company)->create();
    $user = User::factory()->create(['user_type_id' => $userType->id]);
    CompanyUser::create(['user_id' => $user->id, 'level' => 1, 'level_id' => $company->id]);
    test()->actingAs($user);

    $template = ProductTemplate::factory()->for($branch)->create(['name' => 'Moto', 'code_prefix' => $codePrefix]);
    $field = fn (array $attributes) => ProductTemplateField::factory()->for($template, 'template')->create($attributes);

    $marca = $field(['key' => 'marca', 'label' => 'Marca', 'sort' => 1]);
    $chasis = $field(['key' => 'chasis', 'label' => 'Chasis', 'level' => 'unidad', 'required' => true, 'unique' => true, 'in_sri' => true, 'sort' => 2]);
    $field(['key' => 'motor', 'label' => 'Motor', 'level' => 'unidad', 'required' => true, 'unique' => true, 'in_sri' => true, 'sort' => 3]);
    $field(['key' => 'ramv', 'label' => 'RAMV', 'level' => 'unidad', 'unique' => true, 'in_sri' => true, 'sort' => 4]);

    if ($withCodeField) {
        $template->update(['code_field_id' => $chasis->id]);
    }

    $model = Product::factory()->create([
        'branch_id' => $branch->id,
        'type_product' => Product::TYPE_PRODUCT,
        'name' => 'MOTO M1 R1 2026',
        'price1' => 2500,
        'stock' => null,
        'product_template_id' => $template->id,
    ]);
    ProductDetail::factory()->create(['product_id' => $model->id, 'product_template_field_id' => $marca->id, 'value' => 'M1']);

    return [$branch, $model, $template];
}

test('crea una unidad con código prefijo + chasis, hereda del modelo y tiene stock 1', function () {
    [, $model] = unitMotoModel();

    $response = $this->postJson(route('products.units.store', $model), [
        'details' => ['chasis' => '5452', 'motor' => '45262', 'ramv' => '6346653'],
    ]);

    $response->assertCreated()
        ->assertJsonPath('code', 'M5452')
        ->assertJsonPath('name', 'MOTO M1 R1 2026')
        ->assertJsonPath('details', ['chasis' => '5452', 'motor' => '45262', 'ramv' => '6346653'])
        ->assertJsonPath('characteristics', [
            ['label' => 'Marca', 'value' => 'M1', 'in_sri' => false, 'sort' => 1],
            ['label' => 'Chasis', 'value' => '5452', 'in_sri' => true, 'sort' => 2],
            ['label' => 'Motor', 'value' => '45262', 'in_sri' => true, 'sort' => 3],
            ['label' => 'RAMV', 'value' => '6346653', 'in_sri' => true, 'sort' => 4],
        ]);

    $unit = Product::where('code', 'M5452')->first();
    expect($unit->parent_product_id)->toBe($model->id)
        ->and((float) $unit->price1)->toBe(2500.0)
        ->and($unit->stock)->toBe(1.0)
        ->and($unit->product_template_id)->toBeNull();
});

test('sin prefijo el código es el chasis', function () {
    [, $model] = unitMotoModel(codePrefix: null);

    $this->postJson(route('products.units.store', $model), ['details' => ['chasis' => '5452', 'motor' => '45262']])
        ->assertCreated()
        ->assertJsonPath('code', '5452');
});

test('sin campo de código en la plantilla se usa el código enviado', function () {
    [, $model] = unitMotoModel(withCodeField: false);

    $this->postJson(route('products.units.store', $model), ['details' => ['chasis' => '5452', 'motor' => '45262']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['code' => 'El código de la unidad es obligatorio.']);

    $this->postJson(route('products.units.store', $model), ['code' => 'MOTO-01', 'details' => ['chasis' => '5452', 'motor' => '45262']])
        ->assertCreated()
        ->assertJsonPath('code', 'MOTO-01');
});

test('con inventario la unidad entra al kardex como saldo inicial de 1', function () {
    [, $model] = unitMotoModel(inventory: true);

    $this->postJson(route('products.units.store', $model), ['details' => ['chasis' => '5452', 'motor' => '45262']])
        ->assertCreated();

    $unit = Product::where('code', 'M5452')->first();
    expect($unit->fresh()->stock)->toBe(1.0)
        ->and(Inventory::where('product_id', $unit->id)->whereNotNull('warehouse_id')->count())->toBe(1);
});

test('no repite chasis, motor ni código', function () {
    [, $model] = unitMotoModel();
    $this->postJson(route('products.units.store', $model), ['details' => ['chasis' => '5452', 'motor' => '45262']])->assertCreated();

    $this->postJson(route('products.units.store', $model), ['details' => ['chasis' => '5452', 'motor' => '45262']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'details.chasis' => 'Ya existe un producto con Chasis 5452.',
            'details.motor' => 'Ya existe un producto con Motor 45262.',
        ]);
});

test('el código generado no puede chocar con otro producto', function () {
    [$branch, $model] = unitMotoModel();
    Product::factory()->create(['branch_id' => $branch->id, 'code' => 'M5452']);

    $this->postJson(route('products.units.store', $model), ['details' => ['chasis' => '5452', 'motor' => '45262']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['details.chasis' => 'Ya existe un producto con el código M5452.']);
});

test('valida obligatorios de la unidad', function () {
    [, $model] = unitMotoModel();

    $this->postJson(route('products.units.store', $model), ['details' => ['ramv' => '1']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['details.chasis', 'details.motor']);
});

test('un producto sin plantilla o un servicio no tiene unidades', function () {
    [$branch, $model] = unitMotoModel();
    $plain = Product::factory()->create(['branch_id' => $branch->id]);
    $model->update(['type_product' => Product::TYPE_SERVICE]);

    $this->postJson(route('products.units.store', $plain), ['details' => []])
        ->assertUnprocessable()->assertJsonValidationErrors(['product' => 'El producto no tiene una plantilla de características.']);
    $this->postJson(route('products.units.store', $model), ['details' => []])
        ->assertUnprocessable()->assertJsonValidationErrors(['product' => 'Solo un bien (no un servicio) puede tener unidades.']);
});

test('lista las unidades del modelo', function () {
    [, $model] = unitMotoModel();
    $this->postJson(route('products.units.store', $model), ['details' => ['chasis' => 'B2', 'motor' => 'X2']]);
    $this->postJson(route('products.units.store', $model), ['details' => ['chasis' => 'A1', 'motor' => 'X1']]);

    $this->getJson(route('products.units.index', $model))
        ->assertOk()
        ->assertJsonCount(2)
        ->assertJsonPath('0.code', 'MA1')
        ->assertJsonPath('1.code', 'MB2');
});

test('corrige una unidad sin uso y regenera el código; bloquea si ya está en comprobantes', function () {
    [$branch, $model] = unitMotoModel();
    $unitId = $this->postJson(route('products.units.store', $model), ['details' => ['chasis' => '5452', 'motor' => '45262']])->json('id');
    $unit = Product::find($unitId);

    $this->putJson(route('products.units.update', [$model, $unit]), ['details' => ['chasis' => '5453', 'motor' => '45262']])
        ->assertOk()
        ->assertJsonPath('code', 'M5453');

    OrderItem::create([
        'order_id' => Order::factory()->create(['branch_id' => $branch->id])->id,
        'product_id' => $unit->id,
        'quantity' => 1, 'price' => 2500, 'discount' => 0, 'iva' => 0,
    ]);

    $this->putJson(route('products.units.update', [$model, $unit]), ['details' => ['chasis' => '9999', 'motor' => '45262']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['product' => 'La unidad ya se usó en comprobantes y no se puede modificar.']);
});

test('una unidad de otro modelo da 404', function () {
    [$branch, $model] = unitMotoModel();
    $otherModel = Product::factory()->create(['branch_id' => $branch->id, 'product_template_id' => $model->product_template_id]);
    $unitId = $this->postJson(route('products.units.store', $model), ['details' => ['chasis' => '5452', 'motor' => '45262']])->json('id');

    $this->putJson(route('products.units.update', [$otherModel, $unitId]), ['details' => ['chasis' => '5452', 'motor' => '45262']])
        ->assertNotFound();
});

test('lookup encuentra la unidad por chasis o motor y devuelve sus características', function () {
    [, $model] = unitMotoModel();
    $this->postJson(route('products.units.store', $model), ['details' => ['chasis' => '5452', 'motor' => '45262']]);

    $this->getJson(route('products.lookup', ['q' => '45262']))
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.code', 'M5452')
        ->assertJsonPath('0.characteristics.1', ['label' => 'Chasis', 'value' => '5452', 'in_sri' => true, 'sort' => 2]);
});

test('el listado de productos encuentra la unidad por chasis y devuelve parent_product_id y características', function () {
    DB::table('iva_taxes')->insertOrIgnore([['code' => 2, 'percentage' => 12, 'state' => 'active']]);
    [, $model] = unitMotoModel();
    $model->update(['iva' => 2]);
    $this->postJson(route('products.units.store', $model), ['details' => ['chasis' => '5452', 'motor' => '45262']]);

    $this->getJson(route('products.index', ['search' => '5452', 'type' => Product::TYPE_PRODUCT]))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.atts.code', 'M5452')
        ->assertJsonPath('data.0.parent_product_id', $model->id)
        ->assertJsonPath('data.0.product_template_id', null)
        ->assertJsonPath('data.0.characteristics.0', ['label' => 'Marca', 'value' => 'M1', 'in_sri' => false, 'sort' => 1])
        ->assertJsonPath('data.0.characteristics.1.value', '5452');
});

test('listar las unidades no hace consultas por unidad', function () {
    [, $model] = unitMotoModel();
    $queriesFor = function (int $units) use ($model) {
        foreach (range(1, $units) as $i) {
            $this->postJson(route('products.units.store', $model), ['details' => ['chasis' => "C{$units}{$i}", 'motor' => "X{$units}{$i}"]])->assertCreated();
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson(route('products.units.index', $model))->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    expect($queriesFor(1))->toBe($queriesFor(4));
});

test('el listado y el lookup de productos no hacen consultas por unidad', function () {
    [, $model] = unitMotoModel();
    $queriesFor = function (int $units, string $route, array $query) use ($model) {
        foreach (range(1, $units) as $i) {
            $this->postJson(route('products.units.store', $model), ['details' => ['chasis' => "L{$route}{$units}{$i}", 'motor' => "Y{$route}{$units}{$i}"]])->assertCreated();
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson(route($route, $query))->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    expect($queriesFor(1, 'products.index', []))->toBe($queriesFor(4, 'products.index', []))
        ->and($queriesFor(1, 'products.lookup', ['q' => 'MOTO']))->toBe($queriesFor(4, 'products.lookup', ['q' => 'MOTO']));
});
