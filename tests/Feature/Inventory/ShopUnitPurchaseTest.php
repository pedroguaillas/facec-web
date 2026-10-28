<?php

use App\Models\Branch;
use App\Models\EmisionPoint;
use App\Models\Inventory;
use App\Models\Product\Product;
use App\Models\Product\ProductTemplate;
use App\Models\Product\ProductTemplateField;
use App\Models\Provider;
use App\Models\Shop\Shop;
use App\Models\Shop\ShopItem;
use App\Models\VoucherType;
use App\Services\Shop\ShopLcXmlService;
use App\StaticClasses\InventoryMovementTypes;
use App\StaticClasses\PaymentTypes;
use App\StaticClasses\VoucherTypes;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/InventoryTestHelpers.php';

function purchaseMotoModel(Branch $branch): Product
{
    $template = ProductTemplate::factory()->for($branch)->create(['name' => 'Moto', 'code_prefix' => 'M']);
    $chasis = ProductTemplateField::factory()->for($template, 'template')->create([
        'key' => 'chasis', 'label' => 'Chasis', 'level' => 'unidad', 'required' => true, 'unique' => true, 'in_sri' => true, 'sort' => 1,
    ]);
    ProductTemplateField::factory()->for($template, 'template')->create([
        'key' => 'motor', 'label' => 'Motor', 'level' => 'unidad', 'required' => true, 'unique' => true, 'in_sri' => true, 'sort' => 2,
    ]);
    $template->update(['code_field_id' => $chasis->id]);

    return Product::factory()->create([
        'branch_id' => $branch->id,
        'type_product' => Product::TYPE_PRODUCT,
        'name' => 'MOTO M1 R1 2026',
        'price1' => 2500,
        'iva' => 0,
        'stock' => null,
        'product_template_id' => $template->id,
    ]);
}

/**
 * @param  array<int, array<string, mixed>>  $lines
 */
function motoPurchasePayload(Branch $branch, array $lines, string $voucherSlug = VoucherTypes::COMPRA_FACTURA): array
{
    $provider = Provider::factory()->create(['branch_id' => $branch->id, 'type_identification' => 'ruc']);

    return [
        'date' => '2026-10-01',
        'voucher_type_id' => VoucherType::where('slug', $voucherSlug)->value('id'),
        'provider_id' => $provider->id,
        'point_id' => EmisionPoint::factory()->for($branch)->create()->id,
        'serie' => '001-001-000123',
        'total' => 1800 * count($lines),
        'payment_type' => PaymentTypes::CREDITO,
        'due_date' => '2026-10-31',
        'products' => array_map(fn (array $line) => [
            'quantity' => 1, 'price' => 1800, 'discount' => 0, 'iva' => 0, ...$line,
        ], $lines),
    ];
}

function motoLine(Product $model, string $chasis, string $motor): array
{
    return ['product_id' => $model->id, 'unit' => ['chasis' => $chasis, 'motor' => $motor]];
}

test('una línea con unit registra la moto, la línea apunta a ella y entra al kardex al costo de compra', function () {
    [$branch] = voucherInventoryCompany();
    $model = purchaseMotoModel($branch);

    $shopId = $this->postJson(route('shops.store'), motoPurchasePayload($branch, [
        motoLine($model, '5452', '45262'),
        motoLine($model, '5453', '45263'),
    ]))->assertCreated()->json('id');

    $unit = Product::where('code', 'M5452')->first();

    expect($unit->parent_product_id)->toBe($model->id)
        ->and($unit->stock)->toBe(1.0)
        ->and(ShopItem::where('shop_id', $shopId)->pluck('product_id')->all())->toContain($unit->id)
        ->and(Inventory::where('product_id', $unit->id)->where('type', InventoryMovementTypes::PURCHASE)->value('unit_cost'))->toBe(1800.0)
        ->and(Inventory::where('product_id', $unit->id)->where('type', InventoryMovementTypes::OPENING_BALANCE)->exists())->toBeFalse()
        ->and($model->fresh()->stock)->toBeNull()
        ->and(Product::where('code', 'M5453')->value('stock'))->toBe(1.0);
});

test('sin inventario la moto comprada queda con stock 1', function () {
    [$branch] = voucherInventoryCompany(['inventory' => false]);
    $model = purchaseMotoModel($branch);

    $this->postJson(route('shops.store'), motoPurchasePayload($branch, [motoLine($model, '5452', '45262')]))->assertCreated();

    expect(Product::where('code', 'M5452')->value('stock'))->toBe(1.0);
});

test('el modelo no se compra sin los datos de la unidad', function () {
    [$branch] = voucherInventoryCompany();
    $model = purchaseMotoModel($branch);

    $this->postJson(route('shops.store'), motoPurchasePayload($branch, [['product_id' => $model->id]]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['products.0.unit' => 'Ingresa los datos de la unidad (Chasis, Motor).']);
});

test('cada moto va en una línea con cantidad 1', function () {
    [$branch] = voucherInventoryCompany();
    $model = purchaseMotoModel($branch);

    $this->postJson(route('shops.store'), motoPurchasePayload($branch, [[...motoLine($model, '5452', '45262'), 'quantity' => 2]]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['products.0.quantity' => 'Cada unidad se registra en su propia línea con cantidad 1.']);
});

test('valida obligatorios, chasis existente y repetidos entre líneas', function () {
    [$branch] = voucherInventoryCompany();
    $model = purchaseMotoModel($branch);
    $this->postJson(route('products.units.store', $model), ['details' => ['chasis' => '1000', 'motor' => '2000']])->assertCreated();

    $this->postJson(route('shops.store'), motoPurchasePayload($branch, [
        ['product_id' => $model->id, 'unit' => ['chasis' => '5452']],
        motoLine($model, '1000', '3000'),
        motoLine($model, '7777', '8888'),
        motoLine($model, '7777', '9999'),
    ]))->assertUnprocessable()
        ->assertJsonValidationErrors([
            'products.0.unit.motor' => 'Motor es obligatorio.',
            'products.1.unit.chasis' => 'Ya existe un producto con Chasis 1000.',
            'products.3.unit.chasis' => 'Está repetido en otra línea de la compra.',
        ]);

    expect(Product::where('code', 'M7777')->exists())->toBeFalse();
});

test('una nota de débito no registra unidades', function () {
    [$branch] = voucherInventoryCompany();
    $model = purchaseMotoModel($branch);

    $this->postJson(route('shops.store'), motoPurchasePayload($branch, [motoLine($model, '5452', '45262')], VoucherTypes::COMPRA_NOTA_DEBITO))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['products.0.unit' => 'Este tipo de comprobante no registra unidades.']);
});

test('una unidad en stock no se vuelve a comprar', function () {
    [$branch] = voucherInventoryCompany();
    $model = purchaseMotoModel($branch);
    $unitId = $this->postJson(route('products.units.store', $model), ['details' => ['chasis' => '1000', 'motor' => '2000']])->json('id');

    $this->postJson(route('shops.store'), motoPurchasePayload($branch, [['product_id' => $unitId]]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['products.0.product_id' => 'La unidad M1000 ya está en stock.']);
});

test('editar la compra: conserva la moto existente, registra una nueva y descarta la quitada', function () {
    [$branch] = voucherInventoryCompany();
    $model = purchaseMotoModel($branch);
    $shop = Shop::findOrFail($this->postJson(route('shops.store'), motoPurchasePayload($branch, [
        motoLine($model, '5452', '45262'),
        motoLine($model, '5453', '45263'),
    ]))->json('id'));
    $kept = Product::where('code', 'M5452')->first();
    $removed = Product::where('code', 'M5453')->first();

    $this->putJson(route('shops.update', $shop), [
        'total' => 3600,
        'products' => [
            ['product_id' => $kept->id, 'quantity' => 1, 'price' => 1800, 'discount' => 0, 'iva' => 0],
            [...motoLine($model, '6000', '46000'), 'quantity' => 1, 'price' => 1800, 'discount' => 0, 'iva' => 0],
        ],
    ])->assertSuccessful();

    expect($kept->fresh()->stock)->toBe(1.0)
        ->and(Product::where('code', 'M6000')->value('stock'))->toBe(1.0)
        ->and(Product::find($removed->id))->toBeNull()
        ->and(Product::withTrashed()->find($removed->id)->stock)->toBe(0.0);
});

test('editar la compra no quita una moto que ya se vendió', function () {
    [$branch] = voucherInventoryCompany();
    $model = purchaseMotoModel($branch);
    $shop = Shop::findOrFail($this->postJson(route('shops.store'), motoPurchasePayload($branch, [motoLine($model, '5452', '45262')]))->json('id'));
    $unit = Product::where('code', 'M5452')->first();
    orderWithItems($branch, VoucherTypes::VENTA_FACTURA, $unit, 1, 2500);

    $this->putJson(route('shops.update', $shop), ['total' => 0, 'products' => []])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['products' => 'La unidad M5452 ya se vendió y no se puede quitar de la compra.']);
});

test('anular la liquidación retira las motos no vendidas y se bloquea si alguna se vendió', function () {
    [$branch] = voucherInventoryCompany();
    $model = purchaseMotoModel($branch);
    $payload = motoPurchasePayload($branch, [motoLine($model, '5452', '45262')], VoucherTypes::COMPRA_LIQUIDACION_COMPRA);
    Provider::whereKey($payload['provider_id'])->update(['type_identification' => 'cédula']);
    $shop = Shop::findOrFail($this->postJson(route('shops.store'), $payload)->assertCreated()->json('id'));
    $unit = Product::where('code', 'M5452')->first();

    $this->mock(ShopLcXmlService::class)->shouldReceive('cancel')->andReturn(['canceled' => true, 'status' => 'ANULADO']);

    $order = orderWithItems($branch, VoucherTypes::VENTA_FACTURA, $unit, 1, 2500);

    $this->getJson(route('shops.cancel', $shop))
        ->assertUnprocessable()
        ->assertJsonPath('message', 'No se puede anular: las unidades M5452 ya se vendieron.');

    $order->update(['state' => 'ANULADO']);

    $this->getJson(route('shops.cancel', $shop))->assertOk();

    expect(Product::find($unit->id))->toBeNull()
        ->and(Product::withTrashed()->find($unit->id)->stock)->toBe(0.0);
});

test('editar la compra devuelve parent_product_id y características de las motos', function () {
    [$branch] = voucherInventoryCompany();
    $model = purchaseMotoModel($branch);
    $shopId = $this->postJson(route('shops.store'), motoPurchasePayload($branch, [motoLine($model, '5452', '45262')]))->json('id');

    $this->getJson(route('shops.edit', $shopId))
        ->assertOk()
        ->assertJsonPath('shop_items.0.code', 'M5452')
        ->assertJsonPath('shop_items.0.parent_product_id', $model->id)
        ->assertJsonPath('shop_items.0.characteristics', [
            ['label' => 'Chasis', 'value' => '5452', 'in_sri' => true, 'sort' => 1],
            ['label' => 'Motor', 'value' => '45262', 'in_sri' => true, 'sort' => 2],
        ]);
});

test('registrar varias motos no repite las consultas del modelo y su plantilla por línea', function () {
    [$branch] = voucherInventoryCompany();
    $model = purchaseMotoModel($branch);
    $templateQueries = function (array $lines) use ($branch) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->postJson(route('shops.store'), motoPurchasePayload($branch, $lines))->assertCreated();
        DB::disableQueryLog();

        return collect(DB::getQueryLog())->filter(fn ($query) => preg_match('/from `product_template(s|_fields)`/', $query['query']))->count();
    };

    expect($templateQueries([motoLine($model, '1', '1')]))
        ->toBe($templateQueries([motoLine($model, '2', '2'), motoLine($model, '3', '3'), motoLine($model, '4', '4')]));
});
